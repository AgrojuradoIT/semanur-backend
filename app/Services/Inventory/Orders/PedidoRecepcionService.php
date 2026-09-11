<?php

namespace App\Services\Inventory\Orders;

use App\Domain\InventoryOrders\AlertaTipo;
use App\Domain\InventoryOrders\CondicionLinea;
use App\Domain\InventoryOrders\LineaEstado;
use App\Domain\InventoryOrders\NovedadEstado;
use App\Domain\InventoryOrders\NovedadTipo;
use App\Domain\InventoryOrders\OperacionRechazadaException;
use App\Domain\InventoryOrders\PedidoEstado;
use App\Domain\InventoryOrders\RecepcionEstado;
use App\DTO\InventoryOrders\DecimalQuantity;
use App\Models\Bodega;
use App\Models\BodegaProducto;
use App\Models\Categoria;
use App\Models\Empleado;
use App\Models\PedidoAutorizacion;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoNovedad;
use App\Models\PedidoRecepcion;
use App\Models\PedidoRecepcionLinea;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use App\Models\User;
use App\Services\Inventory\Orders\Concerns\GestionaOperacionesPedido;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recepción física de pedidos (U6; plan secciones 5, 6, 8 y 9; R01-R12/R17).
 *
 * - Crear/reanudar sesión de recepción: una recepción borrador vigente por
 *   pedido; el receptor es el empleado activo del usuario autenticado (R12).
 * - Conteo: observación versionada por línea; `null` = no contado, `0.00` =
 *   ausencia explícita (R04); contar NUNCA mueve stock.
 * - Ficha de catálogo: se completa en la LÍNEA; el catálogo real solo nace en
 *   la confirmación que ingresa unidades.
 * - Confirmación: UNA transacción con locks deterministas
 *   (pedido -> líneas -> recepción -> novedades -> productos -> bodega ->
 *   pivots), idempotencia por `pedido_operaciones` y selección de líneas
 *   elegibles calculada en servidor. El observer del ledger es el ÚNICO
 *   proyector de stock; aquí jamás se escriben saldos a mano.
 *
 * Cero OCR, cero red, cero FCM dentro del lock: los avisos son filas
 * persistentes de `pedido_alertas` y la outbox queda pendiente para U8.
 */
class PedidoRecepcionService
{
    use GestionaOperacionesPedido;

    public const COMMAND_CONFIRM = 'confirmar_recepcion';

    public const EVENTO_NOVEDAD_ABIERTA = 'novedad_abierta';

    public const EVENTO_PEDIDO_RECIBIDO = 'pedido_recibido';

    public const EVENTO_RECIBIDO_CON_NOVEDAD = 'recibido_con_novedad';

    /** Todas las líneas cerradas y al menos una con excepción autorizada (U7). */
    public const EVENTO_RECIBIDO_CON_DESFASE = 'recibido_con_desfase';

    public const EVENTO_FICHA_ACTUALIZADA = 'ficha_catalogo_actualizada';

    /** Firma de desfase invalidada por contexto cambiado (U7). */
    public const EVENTO_DESFASE_INVALIDADO = 'desfase_invalidado';

    /** Reintentos acotados SOLO para deadlocks seguros, dentro de la transacción. */
    public const INTENTOS_TX = 3;

    /** @var list<string> */
    public const ESTADOS_RECEPCION = [
        PedidoEstado::PENDIENTE_RECEPCION,
        PedidoEstado::EN_VALIDACION,
        PedidoEstado::PARCIAL_CON_NOVEDAD,
    ];

    public function __construct(
        private readonly PedidoAlertaService $alertas,
    ) {}

    /**
     * Crea la sesión de recepción borrador o devuelve la vigente.
     *
     * @return array{recepcion: PedidoRecepcion, creada: bool}
     */
    public function crearOReanudar(PedidoInventario $pedido, User $actor): array
    {
        $empleado = $this->empleadoActivo($actor);

        return DB::transaction(function () use ($pedido, $actor, $empleado): array {
            $orden = PedidoInventario::query()
                ->whereKey($pedido->pedido_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array((string) $orden->estado, self::ESTADOS_RECEPCION, true)) {
                throw new OperacionRechazadaException(
                    409,
                    'El pedido no admite una sesión de recepción en su estado actual.',
                    ['estado' => (string) $orden->estado],
                );
            }

            $vigente = PedidoRecepcion::query()
                ->where('pedido_id', $orden->pedido_id)
                ->where('estado', RecepcionEstado::BORRADOR)
                ->orderByDesc('recepcion_id')
                ->lockForUpdate()
                ->first();

            if ($vigente !== null) {
                return ['recepcion' => $vigente, 'creada' => false];
            }

            $recepcion = PedidoRecepcion::create([
                'pedido_id' => $orden->pedido_id,
                'estado' => RecepcionEstado::BORRADOR,
                'actor_user_id' => $actor->id,
                'receptor_empleado_id' => $empleado->id,
            ]);

            $lineas = PedidoInventarioLinea::query()
                ->where('pedido_id', $orden->pedido_id)
                ->orderBy('linea_id')
                ->lockForUpdate()
                ->get();

            $ingresadas = $this->lineasConIngreso($lineas->pluck('linea_id')->all());

            foreach ($lineas as $linea) {
                if (LineaEstado::esTerminal((string) $linea->estado)
                    || in_array((int) $linea->linea_id, $ingresadas, true)) {
                    continue;
                }

                PedidoRecepcionLinea::create([
                    'recepcion_id' => $recepcion->recepcion_id,
                    'pedido_linea_id' => $linea->linea_id,
                    'esperado_snapshot' => PedidoSnapshot::cantidadCanonica($linea->cantidad_esperada),
                    'actor_user_id' => $actor->id,
                    'receptor_empleado_id' => $empleado->id,
                    'snapshots' => ['historial' => []],
                ]);
            }

            return ['recepcion' => $recepcion, 'creada' => true];
        }, self::INTENTOS_TX);
    }

    /**
     * Registra el conteo de una línea. No mueve stock; solo reemplaza la
     * observación vigente conservando historial (R05/R06) con versión optimista.
     *
     * @param  array<string, mixed>  $datos
     */
    public function contarLinea(
        PedidoRecepcion $recepcion,
        PedidoInventarioLinea $linea,
        User $actor,
        array $datos,
    ): PedidoRecepcionLinea {
        $empleado = $this->empleadoActivo($actor);

        return DB::transaction(function () use ($recepcion, $linea, $actor, $empleado, $datos): PedidoRecepcionLinea {
            $orden = PedidoInventario::query()
                ->whereKey($recepcion->pedido_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array((string) $orden->estado, self::ESTADOS_RECEPCION, true)) {
                throw new OperacionRechazadaException(409, 'El pedido no admite conteos en su estado actual.');
            }

            $lineaBloqueada = PedidoInventarioLinea::query()
                ->whereKey($linea->linea_id)
                ->where('pedido_id', $orden->pedido_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (LineaEstado::esTerminal((string) $lineaBloqueada->estado)
                || in_array((int) $lineaBloqueada->linea_id, $this->lineasConIngreso([$lineaBloqueada->linea_id]), true)) {
                throw $this->rechazoLineaNoEditable($lineaBloqueada, 'nuevos conteos');
            }

            $recepcionBloqueada = PedidoRecepcion::query()
                ->whereKey($recepcion->recepcion_id)
                ->where('pedido_id', $orden->pedido_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! RecepcionEstado::admiteConteos((string) $recepcionBloqueada->estado)) {
                throw $this->rechazoSesionNoOperable($recepcionBloqueada);
            }

            $observacion = PedidoRecepcionLinea::query()
                ->where('recepcion_id', $recepcionBloqueada->recepcion_id)
                ->where('pedido_linea_id', $lineaBloqueada->linea_id)
                ->lockForUpdate()
                ->first();

            if ($observacion === null) {
                throw new OperacionRechazadaException(409, 'Esta línea no pertenece a la sesión de recepción vigente.');
            }

            $observacion->assertVersion((int) $datos['observacion_version']);

            $total = $datos['conteo_total'] !== null
                ? DecimalQuantity::normalize($datos['conteo_total'], true, 'conteo_total')
                : null;
            $aptos = $datos['conteo_aptos'] !== null
                ? DecimalQuantity::normalize($datos['conteo_aptos'], true, 'conteo_aptos')
                : null;

            $this->registrarHistorial($observacion);

            $observacion->conteo_total = $total;
            $observacion->conteo_aptos = $aptos;
            $observacion->estado_condicion = $total === null ? null : $datos['estado_condicion'];
            $observacion->motivo = $total === null ? null : ($datos['motivo'] ?? null);
            $observacion->actor_user_id = $actor->id;
            $observacion->receptor_empleado_id = $empleado->id;
            $observacion->incrementarVersion();
            $observacion->save();

            if ($total !== null && (string) $orden->estado === PedidoEstado::PENDIENTE_RECEPCION) {
                $orden->transicionarA(PedidoEstado::EN_VALIDACION);
            }

            return $observacion->fresh();
        }, self::INTENTOS_TX);
    }

    /**
     * Completa la ficha propuesta de un producto nuevo en la LÍNEA. Nunca toca
     * el catálogo y queda bloqueada una vez que la línea se ingresa.
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizarFicha(
        PedidoInventarioLinea $linea,
        User $actor,
        array $datos,
    ): PedidoInventarioLinea {
        return DB::transaction(function () use ($linea, $actor, $datos): PedidoInventarioLinea {
            $bloqueada = PedidoInventarioLinea::query()
                ->whereKey($linea->linea_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (LineaEstado::esTerminal((string) $bloqueada->estado)
                || in_array((int) $bloqueada->linea_id, $this->lineasConIngreso([$bloqueada->linea_id]), true)) {
                throw $this->rechazoLineaNoEditable($bloqueada, 'cambios de ficha');
            }

            $bloqueada->assertVersion((int) $datos['version']);

            $antes = $this->fichaSnapshot($bloqueada);

            if (array_key_exists('categoria_propuesta_id', $datos)) {
                $bloqueada->categoria_propuesta_id = $datos['categoria_propuesta_id'];
            }

            if (array_key_exists('alerta_minima_propuesta', $datos)) {
                $bloqueada->alerta_minima_propuesta = $datos['alerta_minima_propuesta'] !== null
                    ? DecimalQuantity::normalize($datos['alerta_minima_propuesta'], true, 'alerta_minima_propuesta')
                    : null;
            }

            if (array_key_exists('precio_costo_confirmado', $datos)) {
                $bloqueada->precio_costo_confirmado = $datos['precio_costo_confirmado'] !== null
                    ? DecimalQuantity::normalize($datos['precio_costo_confirmado'], true, 'precio_costo_confirmado')
                    : null;
            }

            if (array_key_exists('unidad', $datos)) {
                $bloqueada->unidad = $datos['unidad'];
            }

            $bloqueada->incrementarVersion();
            $bloqueada->save();

            $this->registrarEvento(
                (int) $bloqueada->pedido_id,
                (int) $bloqueada->linea_id,
                self::EVENTO_FICHA_ACTUALIZADA,
                $actor,
                $antes,
                $this->fichaSnapshot($bloqueada),
                null,
                $bloqueada->versionActual(),
            );

            return $bloqueada;
        }, self::INTENTOS_TX);
    }

    /**
     * Confirma la recepción: selecciona líneas elegibles en servidor, ingresa
     * solo lo físicamente conforme vía ledger, registra novedades, cierra
     * estados, audita y alerta. Replay exacto devuelve el resultado original.
     *
     * @return array{operacion: \App\Models\PedidoOperacion, replay: bool}
     */
    public function confirmar(
        PedidoInventario $pedido,
        PedidoRecepcion $recepcion,
        User $actor,
        int $versionEsperada,
        string $operationId,
    ): array {
        $empleado = $this->empleadoActivo($actor);

        $hash = $this->hashOperacionPedido([
            'pedido_uuid' => $pedido->uuid,
            'command' => self::COMMAND_CONFIRM,
            'recepcion_id' => (int) $recepcion->recepcion_id,
            'version' => $versionEsperada,
        ]);

        if ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND_CONFIRM, $operationId)) {
            return $this->replayOperacionPedido($existente, $hash);
        }

        try {
            $resultado = DB::transaction(function () use (
                $pedido,
                $recepcion,
                $actor,
                $empleado,
                $versionEsperada,
                $operationId,
                $hash,
            ): array {
                if ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND_CONFIRM, $operationId, true)) {
                    return $this->replayOperacionPedido($existente, $hash);
                }

                $orden = PedidoInventario::query()
                    ->whereKey($pedido->pedido_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! in_array((string) $orden->estado, self::ESTADOS_RECEPCION, true)) {
                    throw new OperacionRechazadaException(
                        409,
                        'El pedido no admite una confirmación de recepción en su estado actual.',
                        ['estado' => (string) $orden->estado],
                    );
                }

                $lineas = PedidoInventarioLinea::query()
                    ->where('pedido_id', $orden->pedido_id)
                    ->orderBy('linea_id')
                    ->lockForUpdate()
                    ->get();

                $recepcionBloqueada = PedidoRecepcion::query()
                    ->whereKey($recepcion->recepcion_id)
                    ->where('pedido_id', $orden->pedido_id)
                    ->lockForUpdate()
                    ->first();

                if ($recepcionBloqueada === null) {
                    throw new OperacionRechazadaException(404, 'La recepción no pertenece a este pedido.');
                }

                if (! RecepcionEstado::admiteConteos((string) $recepcionBloqueada->estado)) {
                    throw $this->rechazoSesionNoOperable($recepcionBloqueada);
                }

                $recepcionBloqueada->assertVersion($versionEsperada);

                $lineaIds = $lineas->pluck('linea_id')->map(fn ($id): int => (int) $id)->all();

                $novedades = PedidoNovedad::query()
                    ->whereIn('pedido_linea_id', $lineaIds)
                    ->orderBy('novedad_id')
                    ->lockForUpdate()
                    ->get();

                $observaciones = PedidoRecepcionLinea::query()
                    ->where('recepcion_id', $recepcionBloqueada->recepcion_id)
                    ->orderBy('recepcion_linea_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('pedido_linea_id');

                $anteriores = PedidoRecepcionLinea::query()
                    ->whereIn('pedido_linea_id', $lineaIds)
                    ->where('recepcion_id', '!=', $recepcionBloqueada->recepcion_id)
                    ->orderByDesc('recepcion_linea_id')
                    ->get()
                    ->groupBy('pedido_linea_id')
                    ->map(fn (Collection $grupo): PedidoRecepcionLinea => $grupo->first());

                $ingresadas = $this->lineasConIngreso($lineaIds);

                // Firma de desfase (U7): solo las novedades abiertas admiten
                // autorización vigente. El lock sigue el orden determinista.
                $novedadesAbiertas = $novedades->filter(
                    fn (PedidoNovedad $novedad): bool => (string) $novedad->estado === NovedadEstado::ABIERTA,
                );

                $autorizacionesVigentes = $novedadesAbiertas->isEmpty()
                    ? new Collection()
                    : PedidoAutorizacion::query()
                        ->whereIn('novedad_id', $novedadesAbiertas->pluck('novedad_id')->all())
                        ->where('estado', PedidoAutorizacion::ESTADO_VIGENTE)
                        ->orderBy('autorizacion_id')
                        ->lockForUpdate()
                        ->get()
                        ->groupBy('novedad_id');

                [$plan, $sinContar, $invalidaciones] = $this->construirPlan(
                    $orden,
                    $lineas,
                    $observaciones,
                    $anteriores,
                    $novedades,
                    $autorizacionesVigentes,
                    $ingresadas,
                );

                if ($sinContar !== []) {
                    throw new OperacionRechazadaException(
                        409,
                        'La confirmación exige contar las líneas pendientes del pedido.',
                        ['lineas_sin_contar' => $sinContar],
                    );
                }

                // Contexto cambiado: la firma se invalida con auditoría y su
                // línea no se ingresa (R12), dentro de la misma transacción.
                foreach ($invalidaciones as $invalidacion) {
                    $this->invalidarAutorizacion(
                        $invalidacion['autorizacion'],
                        $invalidacion['novedad'],
                        (string) $invalidacion['motivo'],
                        $actor,
                        $operationId,
                        $orden,
                    );
                }

                // ── Resolución de productos: existente se enlaza, nuevo se crea
                //    solo si la ficha está completa y hay unidades que ingresan.
                $nuevos = [];
                $bloqueosFicha = [];

                foreach ($plan as $indice => $item) {
                    if (! $item['ingestable']) {
                        continue;
                    }

                    /** @var PedidoInventarioLinea $linea */
                    $linea = $item['linea'];

                    if ($linea->producto_id !== null) {
                        $plan[$indice]['producto_id'] = (int) $linea->producto_id;
                        continue;
                    }

                    $sku = trim((string) $linea->sku_confirmado);
                    $existente = $sku !== ''
                        ? Producto::query()->where('producto_sku', $sku)->first()
                        : null;

                    if ($existente !== null) {
                        $plan[$indice]['producto_id'] = (int) $existente->producto_id;
                        continue;
                    }

                    if (! $this->fichaCompleta($linea)) {
                        // La firma vigente NO se invalida: la línea sigue
                        // bloqueada y la autorización espera a la ficha.
                        $plan[$indice]['ingestable'] = false;
                        $plan[$indice]['tipo_novedad'] = NovedadTipo::FICHA_INCOMPLETA;
                        $bloqueosFicha[] = (int) $linea->linea_id;
                        continue;
                    }

                    $clave = mb_strtoupper($sku);
                    $firma = $this->firmaFicha($linea);

                    if (isset($nuevos[$clave])) {
                        if ($nuevos[$clave]['firma'] !== $firma) {
                            throw new OperacionRechazadaException(
                                409,
                                'Dos líneas proponen el mismo SKU nuevo con fichas incompatibles; se requiere revisión humana.',
                                ['sku' => $sku, 'requiere_revision_humana' => true],
                            );
                        }

                        $nuevos[$clave]['indices'][] = $indice;
                        continue;
                    }

                    $nuevos[$clave] = [
                        'sku' => $sku,
                        'firma' => $firma,
                        'categoria_id' => (int) $linea->categoria_propuesta_id,
                        'nombre' => trim((string) $linea->descripcion_confirmada),
                        'unidad' => trim((string) $linea->unidad),
                        'alerta' => PedidoSnapshot::cantidadCanonica($linea->alerta_minima_propuesta) ?? '0.00',
                        'precio' => PedidoSnapshot::cantidadCanonica($linea->precio_costo_confirmado) ?? '0.00',
                        'indices' => [$indice],
                    ];
                }

                ksort($nuevos, SORT_STRING);

                foreach ($nuevos as $datosNuevo) {
                    $producto = Producto::create([
                        'categoria_id' => $datosNuevo['categoria_id'],
                        'producto_sku' => $datosNuevo['sku'],
                        'producto_nombre' => $datosNuevo['nombre'],
                        'producto_unidad_medida' => $datosNuevo['unidad'],
                        'producto_stock_actual' => 0,
                        'producto_alerta_stock_minimo' => $datosNuevo['alerta'],
                        'producto_precio_costo' => $datosNuevo['precio'],
                        'producto_ubicacion' => null,
                    ]);

                    foreach ($datosNuevo['indices'] as $indice) {
                        $plan[$indice]['producto_id'] = (int) $producto->producto_id;
                    }
                }

                /** @var list<array<string, mixed>> $postables */
                $postables = array_values(array_filter(
                    $plan,
                    fn (array $item): bool => $item['ingestable'] && isset($item['producto_id']),
                ));

                usort($postables, static function (array $a, array $b): int {
                    return [$a['producto_id'], $a['linea']->linea_id] <=> [$b['producto_id'], $b['linea']->linea_id];
                });

                // ── Locks deterministas: productos por ID ascendente, luego
                //    bodega y pivots. El observer vuelve a tomar el mismo orden.
                $idsProducto = array_values(array_unique(array_map(
                    static fn (array $item): int => (int) $item['producto_id'],
                    $postables,
                )));
                sort($idsProducto, SORT_NUMERIC);

                $productos = $idsProducto === []
                    ? new Collection()
                    : Producto::query()
                        ->whereIn('producto_id', $idsProducto)
                        ->orderBy('producto_id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('producto_id');

                $ingresoPorProducto = [];

                foreach ($postables as $item) {
                    $cantidad = $this->cantidadDeIngreso($item);
                    $productoId = (int) $item['producto_id'];
                    $ingresoPorProducto[$productoId] = isset($ingresoPorProducto[$productoId])
                        ? bcadd($ingresoPorProducto[$productoId], $cantidad, DecimalQuantity::LEDGER_SCALE)
                        : $cantidad;
                }

                foreach ($ingresoPorProducto as $productoId => $cantidad) {
                    $producto = $productos->get($productoId);

                    if ($producto === null) {
                        throw new OperacionRechazadaException(409, 'El producto involucrado cambió durante la recepción; reintenta la operación.');
                    }

                    $limite = $producto->capacidad_maxima;

                    if ($limite === null) {
                        continue;
                    }

                    $limiteCanonico = number_format((float) $limite, DecimalQuantity::LEDGER_SCALE, '.', '');

                    // 0/ausente = sin límite configurado, consistente con analítica.
                    if (bccomp($limiteCanonico, '0.00', DecimalQuantity::LEDGER_SCALE) <= 0) {
                        continue;
                    }

                    $actual = (string) PedidoSnapshot::cantidadCanonica($producto->producto_stock_actual);
                    $proyectado = bcadd($actual, $cantidad, DecimalQuantity::LEDGER_SCALE);

                    if (bccomp($proyectado, $limiteCanonico, DecimalQuantity::LEDGER_SCALE) > 0) {
                        throw new OperacionRechazadaException(
                            409,
                            'El ingreso supera la capacidad máxima de almacenamiento del producto.',
                            [
                                'producto_id' => $productoId,
                                'capacidad_maxima' => $limiteCanonico,
                                'stock_proyectado' => $proyectado,
                            ],
                        );
                    }
                }

                $bodega = Bodega::query()->lockForUpdate()->find($orden->bodega_id);

                if ($bodega === null) {
                    throw new OperacionRechazadaException(409, 'La bodega destino del pedido ya no existe.');
                }

                if ($idsProducto !== []) {
                    BodegaProducto::query()
                        ->where('bodega_id', $bodega->bodega_id)
                        ->whereIn('producto_id', $idsProducto)
                        ->orderBy('producto_id')
                        ->lockForUpdate()
                        ->get();
                }

                // ── Un ingreso definitivo por línea; el observer proyecta.
                $posteadas = [];

                foreach ($postables as $item) {
                    $linea = $item['linea'];
                    $cantidad = $this->cantidadDeIngreso($item);
                    $autorizacion = $item['autorizacion'];
                    $conDesfase = $autorizacion instanceof PedidoAutorizacion;

                    $transaccion = TransaccionInventario::query()->forceCreate([
                        'producto_id' => (int) $item['producto_id'],
                        'bodega_id' => (int) $bodega->bodega_id,
                        'usuario_id' => (int) $actor->id,
                        'transaccion_tipo' => 'ingreso',
                        'transaccion_cantidad' => $cantidad,
                        'transaccion_motivo' => 'Recepción de pedido '.$orden->numero,
                        'transaccion_referencia_type' => 'pedido_inventario',
                        'transaccion_referencia_id' => (int) $orden->pedido_id,
                        'transaccion_notas' => $conDesfase
                            ? 'Ingreso con desfase autorizado (autorización #'.(int) $autorizacion->autorizacion_id.')'
                            : 'Ingreso definitivo de recepción conforme',
                        'pedido_linea_id' => (int) $linea->linea_id,
                    ]);

                    $posteadas[] = [
                        'linea' => $linea,
                        'producto_id' => (int) $item['producto_id'],
                        'cantidad' => $cantidad,
                        'transaccion_id' => (int) $transaccion->transaccion_id,
                        'autorizacion' => $autorizacion,
                        'novedad' => $item['novedad'],
                    ];
                }

                // ── Cierre de líneas ingresadas: conforme, parcial con saldo
                //    abierto (U10) o con desfase autorizado (consumo de la
                //    firma dentro de la transacción).
                $divididas = [];

                $maxOrden = (int) PedidoInventarioLinea::query()
                    ->where('pedido_id', $orden->pedido_id)
                    ->max('orden');

                foreach ($postables as $item) {
                    $this->confirmarObservacion($item['observacion'], $recepcionBloqueada);

                    if ($item['autorizacion'] instanceof PedidoAutorizacion) {
                        $this->cerrarLineaConDesfase($item['linea'], (int) $item['producto_id']);
                        $item['autorizacion']->consumir($recepcionBloqueada);

                        $novedad = $item['novedad'];
                        if ($novedad instanceof PedidoNovedad && $novedad->estaAbierta()) {
                            $novedad->transicionarA(NovedadEstado::CERRADA, ['cerrada_at' => now()]);
                        }

                        continue;
                    }

                    // Saldo abierto (U10): el faltante puro ya ingresó Q=A en
                    // el movimiento de arriba; el remanente queda abierto en
                    // una hija vinculada en lugar de cerrar la línea.
                    if (($item['split_saldo'] ?? null) !== null && isset($item['producto_id'])) {
                        $maxOrden++;

                        $divididas[] = $this->dividirSaldoAbierto(
                            $item['linea'],
                            $item['observacion'],
                            $novedades,
                            (int) $item['producto_id'],
                            (string) $item['cantidad_ingreso'],
                            (string) $item['split_saldo'],
                            $maxOrden,
                            (int) $empleado->id,
                        );

                        continue;
                    }

                    $this->cerrarLineaIngresada($item['linea'], (int) $item['producto_id']);

                    $abierta = $novedades->first(fn (PedidoNovedad $novedad): bool => (int) $novedad->pedido_linea_id === (int) $item['linea']->linea_id
                        && (string) $novedad->estado === NovedadEstado::ABIERTA);

                    if ($abierta !== null) {
                        $abierta->transicionarA(NovedadEstado::RESUELTA, ['cerrada_at' => now()]);
                    }
                }

                // ── Cierre sin ingreso (A=0, política habilitada): consume la
                //    firma y cierra la novedad SIN transacción de cantidad cero.
                $cerradasSinIngreso = [];

                foreach ($plan as $item) {
                    if (! $item['cierre_sin_ingreso'] || ! ($item['autorizacion'] instanceof PedidoAutorizacion)) {
                        continue;
                    }

                    $this->confirmarObservacion($item['observacion'], $recepcionBloqueada);
                    $this->cerrarLineaSinIngreso($item['linea']);
                    $item['autorizacion']->consumir($recepcionBloqueada);

                    $novedad = $item['novedad'];
                    if ($novedad instanceof PedidoNovedad && $novedad->estaAbierta()) {
                        $novedad->transicionarA(NovedadEstado::CERRADA, ['cerrada_at' => now()]);
                    }

                    $cerradasSinIngreso[] = $item;
                }

                // ── Líneas bloqueadas: novedad abierta (o ficha incompleta).
                $novedadesNuevasOModificadas = [];
                $bloqueadasRespuesta = [];

                foreach ($plan as $item) {
                    if (($item['ingestable'] && isset($item['producto_id'])) || $item['cierre_sin_ingreso']) {
                        continue;
                    }

                    /** @var PedidoInventarioLinea $linea */
                    $linea = $item['linea'];
                    /** @var PedidoRecepcionLinea $observacion */
                    $observacion = $item['observacion'];
                    $tipo = (string) ($item['tipo_novedad'] ?? NovedadTipo::OTRO);
                    $motivoBloqueo = $item['bloqueo_autorizacion'];

                    if ($tipo === NovedadTipo::FICHA_INCOMPLETA) {
                        // No hay desviación física: no se abre novedad; la ficha
                        // se completa y se reintenta la confirmación. Si ya
                        // existía novedad (firma de desfase pendiente), se
                        // conserva abierta y la autorización sigue vigente.
                        if ((string) $linea->estado === LineaEstado::PENDIENTE) {
                            $linea->transicionarA(LineaEstado::CONFORME);
                        }

                        $this->confirmarObservacion($observacion, $recepcionBloqueada);
                        $bloqueadasRespuesta[] = [
                            'linea' => $linea,
                            'issue_id' => $item['novedad'] instanceof PedidoNovedad ? (int) $item['novedad']->novedad_id : null,
                            'reason' => $tipo,
                            'motivo_bloqueo' => $motivoBloqueo,
                        ];
                        continue;
                    }

                    if ((string) $linea->estado !== LineaEstado::NOVEDAD) {
                        $linea->transicionarA(LineaEstado::NOVEDAD);
                    }

                    $novedad = $item['novedad'] instanceof PedidoNovedad
                        ? $item['novedad']
                        : $novedades->first(fn (PedidoNovedad $n): bool => (int) $n->pedido_linea_id === (int) $linea->linea_id
                            && (string) $n->estado === NovedadEstado::ABIERTA);

                    $esNuevaOModificada = false;

                    if ($novedad !== null) {
                        if ((int) $novedad->recepcion_linea_id !== (int) $observacion->recepcion_linea_id) {
                            $novedad->fill($this->datosNovedad($linea, $observacion, $tipo, (int) $empleado->id));
                            $novedad->save();
                            $esNuevaOModificada = true;
                        }
                    } else {
                        $novedad = PedidoNovedad::create($this->datosNovedad($linea, $observacion, $tipo, (int) $empleado->id) + [
                            'abierta_at' => now(),
                        ]);
                        $esNuevaOModificada = true;
                    }

                    if ($esNuevaOModificada) {
                        $novedadesNuevasOModificadas[] = (int) $novedad->novedad_id;
                    }

                    $this->confirmarObservacion($observacion, $recepcionBloqueada);
                    $bloqueadasRespuesta[] = [
                        'linea' => $linea,
                        'issue_id' => (int) $novedad->novedad_id,
                        'reason' => $tipo,
                        'motivo_bloqueo' => $motivoBloqueo,
                    ];
                }

                // ── Estado agregado calculado en servidor: terminal solo si
                //    TODAS las líneas (incluidas las hijas de saldo abierto)
                //    están cerradas y no queda novedad abierta; con al menos
                //    una excepción autorizada (desfase o cierre sin ingreso)
                //    es con desfase.
                $lineasFinales = PedidoInventarioLinea::query()
                    ->where('pedido_id', $orden->pedido_id)
                    ->get();

                $todasTerminales = $lineasFinales->every(
                    fn (PedidoInventarioLinea $linea): bool => LineaEstado::esTerminal((string) $linea->estado),
                );

                $conDesfase = $lineasFinales->contains(
                    fn (PedidoInventarioLinea $linea): bool => in_array((string) $linea->estado, [
                        LineaEstado::RECIBIDA_CON_DESFASE,
                        LineaEstado::CIERRE_SIN_INGRESO,
                    ], true),
                );

                $novedadesAbiertasFinales = $todasTerminales
                    ? PedidoNovedad::query()
                        ->whereIn('pedido_linea_id', $lineasFinales->pluck('linea_id')->all())
                        ->where('estado', NovedadEstado::ABIERTA)
                        ->count()
                    : 0;

                $estadoFinal = (! $todasTerminales || $novedadesAbiertasFinales > 0)
                    ? PedidoEstado::PARCIAL_CON_NOVEDAD
                    : ($conDesfase ? PedidoEstado::RECIBIDO_CON_DESFASE : PedidoEstado::RECIBIDO);

                $orden->transicionarA($estadoFinal);

                $recepcionBloqueada->estado = RecepcionEstado::CONFIRMADA;
                $recepcionBloqueada->clave_operacion = $operationId;
                $recepcionBloqueada->confirmada_at = now();
                $recepcionBloqueada->incrementarVersion();
                $recepcionBloqueada->save();

                // ── Auditoría y alertas persistentes (sin FCM ni red). Las
                //    hijas de saldo abierto abren novedad propia: entran en la
                //    cobertura agrupada con su vínculo de linaje en datos.
                foreach ($divididas as $division) {
                    $novedadesNuevasOModificadas[] = (int) $division['hija_issue_id'];
                }

                if ($novedadesNuevasOModificadas !== []) {
                    $eventoNovedad = $this->registrarEvento(
                        (int) $orden->pedido_id,
                        null,
                        self::EVENTO_NOVEDAD_ABIERTA,
                        $actor,
                        null,
                        [
                            'recepcion_id' => (int) $recepcionBloqueada->recepcion_id,
                            'novedades' => $novedadesNuevasOModificadas,
                            'lineas_bloqueadas' => array_map(
                                static fn (array $item): int => (int) $item['linea']->linea_id,
                                $bloqueadasRespuesta,
                            ),
                            'splits' => array_map(
                                static fn (array $division): array => [
                                    'linea_origen_id' => (int) $division['linea_id'],
                                    'hija_linea_id' => (int) $division['hija_linea_id'],
                                    'cantidad_ingresada' => $division['cantidad_ingresada'],
                                    'saldo_restante' => $division['saldo_restante'],
                                    'novedad_origen_id' => $division['novedad_origen_id'],
                                    'novedad_hija_id' => (int) $division['hija_issue_id'],
                                ],
                                $divididas,
                            ),
                        ],
                        $operationId,
                        $orden->versionActual(),
                    );

                    $this->alertas->crearParaEvento(
                        $eventoNovedad,
                        AlertaTipo::NOVEDAD_ABIERTA,
                        $this->alertas->destinatariosNovedad($orden),
                    );
                }

                $tipoResumen = match ($estadoFinal) {
                    PedidoEstado::RECIBIDO => self::EVENTO_PEDIDO_RECIBIDO,
                    PedidoEstado::RECIBIDO_CON_DESFASE => self::EVENTO_RECIBIDO_CON_DESFASE,
                    default => self::EVENTO_RECIBIDO_CON_NOVEDAD,
                };

                $eventoResumen = $this->registrarEvento(
                    (int) $orden->pedido_id,
                    null,
                    $tipoResumen,
                    $actor,
                    null,
                    [
                        'recepcion_id' => (int) $recepcionBloqueada->recepcion_id,
                        'estado' => $estadoFinal,
                        'lineas_posteadas' => count($posteadas),
                        'lineas_bloqueadas' => count($bloqueadasRespuesta),
                        'lineas_ficha_incompleta' => $bloqueosFicha,
                        'lineas_cerradas_sin_ingreso' => array_map(
                            static fn (array $item): int => (int) $item['linea']->linea_id,
                            $cerradasSinIngreso,
                        ),
                        'autorizaciones_consumidas' => $this->idsDeAutorizaciones([...$posteadas, ...$cerradasSinIngreso]),
                        'autorizaciones_invalidadas' => array_map(
                            static fn (array $item): int => (int) $item['autorizacion']->autorizacion_id,
                            $invalidaciones,
                        ),
                        'movimientos' => array_map(
                            static fn (array $item): array => [
                                'linea_id' => (int) $item['linea']->linea_id,
                                'producto_id' => (int) $item['producto_id'],
                                'cantidad' => $item['cantidad'],
                                'transaccion_id' => $item['transaccion_id'],
                                'autorizacion_id' => $item['autorizacion'] instanceof PedidoAutorizacion
                                    ? (int) $item['autorizacion']->autorizacion_id
                                    : null,
                            ],
                            $posteadas,
                        ),
                        'lineas_recibidas_parcial' => array_map(
                            static fn (array $division): int => (int) $division['linea_id'],
                            $divididas,
                        ),
                        'splits' => array_map(
                            static fn (array $division): array => [
                                'linea_origen_id' => (int) $division['linea_id'],
                                'hija_linea_id' => (int) $division['hija_linea_id'],
                                'cantidad_ingresada' => $division['cantidad_ingresada'],
                                'saldo_restante' => $division['saldo_restante'],
                                'novedad_origen_id' => $division['novedad_origen_id'],
                                'novedad_hija_id' => (int) $division['hija_issue_id'],
                            ],
                            $divididas,
                        ),
                    ],
                    $operationId,
                    $orden->versionActual(),
                );

                $this->alertas->crearParaEvento(
                    $eventoResumen,
                    $tipoResumen,
                    $this->alertas->contablesLectores(),
                );

                $payload = [
                    'receipt_id' => (int) $recepcionBloqueada->recepcion_id,
                    'order_id' => $orden->uuid,
                    'resulting_state' => $estadoFinal,
                    'posted_lines' => array_map(
                        static fn (array $item): array => [
                            'line_id' => (int) $item['linea']->linea_id,
                            'producto_id' => (int) $item['producto_id'],
                            'quantity' => $item['cantidad'],
                            'transaction_id' => (int) $item['transaccion_id'],
                            'authorization_id' => $item['autorizacion'] instanceof PedidoAutorizacion
                                ? (int) $item['autorizacion']->autorizacion_id
                                : null,
                        ],
                        $posteadas,
                    ),
                    'blocked_lines' => array_map(
                        static fn (array $item): array => [
                            'line_id' => (int) $item['linea']->linea_id,
                            'issue_id' => $item['issue_id'],
                            'reason' => $item['reason'],
                            'motivo_bloqueo' => $item['motivo_bloqueo'],
                        ],
                        $bloqueadasRespuesta,
                    ),
                    'closed_without_stock' => array_map(
                        static fn (array $item): array => [
                            'line_id' => (int) $item['linea']->linea_id,
                            'issue_id' => $item['novedad'] instanceof PedidoNovedad ? (int) $item['novedad']->novedad_id : null,
                            'authorization_id' => (int) $item['autorizacion']->autorizacion_id,
                            'reason' => 'cierre_sin_ingreso',
                        ],
                        $cerradasSinIngreso,
                    ),
                    'authorizations_invalidated' => array_map(
                        static fn (array $item): array => [
                            'authorization_id' => (int) $item['autorizacion']->autorizacion_id,
                            'line_id' => (int) $item['novedad']->pedido_linea_id,
                            'issue_id' => (int) $item['novedad']->novedad_id,
                            'reason' => (string) $item['motivo'],
                        ],
                        $invalidaciones,
                    ),
                    'receipt_version' => $recepcionBloqueada->versionActual(),
                    'order_version' => $orden->versionActual(),
                    'splits' => array_map(
                        static fn (array $division): array => [
                            'line_id' => (int) $division['linea_id'],
                            'hija_line_id' => (int) $division['hija_linea_id'],
                            'hija_issue_id' => (int) $division['hija_issue_id'],
                            'parent_linea_id' => (int) $division['linea_id'],
                            'quantity' => $division['cantidad_ingresada'],
                            'saldo_restante' => $division['saldo_restante'],
                            'novedad_origen_id' => $division['novedad_origen_id'],
                        ],
                        $divididas,
                    ),
                    'novedades_abiertas' => PedidoNovedad::query()
                        ->whereIn('pedido_linea_id', array_merge(
                            $lineaIds,
                            array_map(
                                static fn (array $division): int => (int) $division['hija_linea_id'],
                                $divididas,
                            ),
                        ))
                        ->where('estado', NovedadEstado::ABIERTA)
                        ->count(),
                ];

                return [
                    'operacion' => $this->registrarOperacionPedido(
                        (int) $actor->id,
                        self::COMMAND_CONFIRM,
                        $operationId,
                        $hash,
                        (string) $orden->uuid,
                        $payload,
                    ),
                    'replay' => false,
                ];
            }, self::INTENTOS_TX);
        } catch (QueryException $exception) {
            if ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND_CONFIRM, $operationId)) {
                return $this->replayOperacionPedido($existente, $hash);
            }

            if ($this->esViolacionUnicaSkuProducto($exception)) {
                throw new OperacionRechazadaException(
                    409,
                    'Otra operación creó el SKU nuevo al mismo tiempo. Revisa la ficha antes de reintentar.',
                    ['requiere_revision_humana' => true],
                );
            }

            // La colisión de unique(pedido_linea_id) NUNCA se captura: si el
            // observer proyectó antes del INSERT, el rollback completo es la
            // única salida segura (R08).
            throw $exception;
        }

        return $resultado;
    }

    /**
     * Plan de confirmación: solo líneas no terminales; las ya ingresadas se
     * excluyen sin recontar (R17). `null` nunca cuenta (R04). Una novedad
     * abierta con firma vigente y contexto intacto habilita el ingreso de
     * Q = A (`recibida_con_desfase`) o el cierre sin ingreso cuando A = 0.
     * Un faltante puro (C = A < E, condición conforme, sin firma) ingresa
     * Q = A y deja el saldo D = E − A abierto en una hija (U10, split).
     *
     * @param  Collection<int, PedidoInventarioLinea>  $lineas
     * @param  Collection<int, PedidoRecepcionLinea>  $observaciones
     * @param  Collection<int, PedidoRecepcionLinea>  $anteriores
     * @param  Collection<int, PedidoNovedad>  $novedades
     * @param  Collection<int, Collection<int, PedidoAutorizacion>>  $autorizacionesVigentes
     * @param  list<int>  $ingresadas
     * @return array{0: list<array<string, mixed>>, 1: list<int>, 2: list<array<string, mixed>>}
     */
    private function construirPlan(
        PedidoInventario $orden,
        Collection $lineas,
        Collection $observaciones,
        Collection $anteriores,
        Collection $novedades,
        Collection $autorizacionesVigentes,
        array $ingresadas,
    ): array {
        $plan = [];
        $sinContar = [];
        $invalidaciones = [];

        foreach ($lineas as $linea) {
            $esTerminal = LineaEstado::esTerminal((string) $linea->estado);

            if (in_array((int) $linea->linea_id, $ingresadas, true)) {
                if (! $esTerminal) {
                    throw new OperacionRechazadaException(
                        409,
                        'Inconsistencia de inventario: la línea ya tiene un ingreso definitivo sin estar cerrada.',
                        ['linea_id' => (int) $linea->linea_id],
                    );
                }

                continue;
            }

            if ($esTerminal) {
                continue;
            }

            $novedad = $novedades->first(fn (PedidoNovedad $n): bool => (int) $n->pedido_linea_id === (int) $linea->linea_id
                && (string) $n->estado === NovedadEstado::ABIERTA);

            $candidatas = $novedad !== null
                ? ($autorizacionesVigentes->get($novedad->novedad_id) ?? new Collection())
                : new Collection();

            $observacionSesion = $observaciones->get($linea->linea_id);
            $observacionContada = $observacionSesion !== null && $observacionSesion->conteo_total !== null
                ? $observacionSesion
                : null;

            // Una línea financiada por una firma vigente puede confirmarse sin
            // recontar en la sesión nueva: rige el conteo que abrió la novedad.
            // Sin firma, un conteo nulo en la sesión vigente exige recontar.
            $observacion = $observacionContada
                ?? (($novedad !== null && $candidatas->isNotEmpty()) ? $anteriores->get($linea->linea_id) : null);

            if ($observacion === null || $observacion->conteo_total === null || $observacion->conteo_aptos === null) {
                $sinContar[] = (int) $linea->linea_id;
                continue;
            }

            $esperado = (string) PedidoSnapshot::cantidadCanonica($linea->cantidad_esperada);
            $total = (string) PedidoSnapshot::cantidadCanonica($observacion->conteo_total);
            $aptos = (string) PedidoSnapshot::cantidadCanonica($observacion->conteo_aptos);

            $conforme = CondicionLinea::esConforme($observacion->estado_condicion)
                && DecimalQuantity::compare($total, $esperado) === 0
                && DecimalQuantity::compare($aptos, $esperado) === 0;

            $item = [
                'linea' => $linea,
                'observacion' => $observacion,
                'conforme' => $conforme,
                'tipo_novedad' => $conforme ? null : $this->clasificarNovedad($observacion, $total, $aptos, $esperado),
                'novedad' => $novedad,
                'autorizacion' => null,
                'ingestable' => $conforme,
                'cantidad_ingreso' => null,
                'split_saldo' => null,
                'cierre_sin_ingreso' => false,
                'bloqueo_autorizacion' => null,
            ];

            if ($novedad !== null && $candidatas->isNotEmpty()) {
                $this->aplicarFirmaDesfase($orden, $item, $candidatas, $invalidaciones);
            }

            // Saldo abierto (U10): faltante puro sin firma deliberada. La
            // firma vigente (desfase o cierre) conserva su camino de cierre;
            // sin firma, lo apto ingresa ahora y el saldo queda abierto.
            if ($item['autorizacion'] === null && ! $item['cierre_sin_ingreso']) {
                $this->aplicarSaldoAbierto($item, $observacion, $total, $aptos, $esperado);
            }

            $plan[] = $item;
        }

        return [$plan, $sinContar, $invalidaciones];
    }

    /**
     * Evalúa la firma vigente contra el contexto actual: consistente habilita
     * Q = A; cambiada se invalida con auditoría y la línea queda bloqueada.
     *
     * @param  array<string, mixed>  $item
     * @param  Collection<int, PedidoAutorizacion>  $candidatas
     * @param  list<array<string, mixed>>  $invalidaciones
     */
    private function aplicarFirmaDesfase(
        PedidoInventario $orden,
        array &$item,
        Collection $candidatas,
        array &$invalidaciones,
    ): void {
        /** @var PedidoInventarioLinea $linea */
        $linea = $item['linea'];
        /** @var PedidoNovedad $novedad */
        $novedad = $item['novedad'];
        /** @var PedidoRecepcionLinea $observacion */
        $observacion = $item['observacion'];

        /** @var PedidoAutorizacion $candidata */
        $candidata = $candidatas->sortByDesc('autorizacion_id')->first();

        // La línea se resolvió por reconteo: la firma perdió su contexto.
        if ($item['conforme']) {
            foreach ($candidatas as $vigente) {
                $invalidaciones[] = ['autorizacion' => $vigente, 'novedad' => $novedad, 'motivo' => 'linea_resuelta_por_reconteo'];
            }

            return;
        }

        $contexto = DesfaseContexto::capturar($orden, $linea, $observacion);

        if (! hash_equals((string) $candidata->contexto_hash, DesfaseContexto::hash($contexto))) {
            foreach ($candidatas as $vigente) {
                $invalidaciones[] = ['autorizacion' => $vigente, 'novedad' => $novedad, 'motivo' => 'contexto_cambiado'];
            }

            $item['bloqueo_autorizacion'] = 'autorizacion_invalida';

            return;
        }

        foreach ($candidatas as $vigente) {
            if ((int) $vigente->autorizacion_id !== (int) $candidata->autorizacion_id) {
                $invalidaciones[] = ['autorizacion' => $vigente, 'novedad' => $novedad, 'motivo' => 'reemplazada'];
            }
        }

        $q = (string) PedidoSnapshot::cantidadCanonica($candidata->cantidad_autorizada);

        if (DecimalQuantity::compare($q, '0.00') === 0) {
            if (! (bool) config('inventory_orders.cierre_sin_ingreso_permitido', false)) {
                $invalidaciones[] = ['autorizacion' => $candidata, 'novedad' => $novedad, 'motivo' => 'cierre_sin_ingreso_no_permitido'];
                $item['bloqueo_autorizacion'] = 'cierre_sin_ingreso_no_permitido';

                return;
            }

            $item['autorizacion'] = $candidata;
            $item['cierre_sin_ingreso'] = true;
            $item['ingestable'] = false;

            return;
        }

        $item['autorizacion'] = $candidata;
        $item['ingestable'] = true;
        $item['cantidad_ingreso'] = $q;
    }

    /**
     * Marca un faltante puro para saldo abierto (U10): C = A < E con
     * condición conforme y sin firma deliberada. Lo apto ingresa ahora
     * (Q = A) y el saldo D = E − A queda abierto en una hija vinculada.
     * Daño, SKU distinto, aptos insuficientes (C > A), sobrantes, A = 0 y
     * D <= 0 conservan sus caminos existentes.
     *
     * @param  array<string, mixed>  $item
     */
    private function aplicarSaldoAbierto(
        array &$item,
        PedidoRecepcionLinea $observacion,
        string $total,
        string $aptos,
        string $esperado,
    ): void {
        if (($item['tipo_novedad'] ?? null) !== NovedadTipo::FALTANTE) {
            return;
        }

        if (! CondicionLinea::esConforme($observacion->estado_condicion)) {
            return;
        }

        if (DecimalQuantity::compare($total, $aptos) !== 0) {
            return;
        }

        if (DecimalQuantity::compare($aptos, '0.00') <= 0) {
            return;
        }

        $saldo = bcsub($esperado, $aptos, DecimalQuantity::LEDGER_SCALE);

        if (bccomp($saldo, '0.00', DecimalQuantity::LEDGER_SCALE) <= 0) {
            return;
        }

        $item['ingestable'] = true;
        $item['cantidad_ingreso'] = $aptos;
        $item['split_saldo'] = $saldo;
    }

    private function clasificarNovedad(
        PedidoRecepcionLinea $observacion,
        string $total,
        string $aptos,
        string $esperado,
    ): string {
        $condicion = (string) $observacion->estado_condicion;

        if ($condicion === CondicionLinea::SKU_INCORRECTO) {
            return NovedadTipo::SKU_INCORRECTO;
        }

        if ($condicion === CondicionLinea::DANADO) {
            return NovedadTipo::DANADO;
        }

        if ($condicion === CondicionLinea::OTRO) {
            return NovedadTipo::OTRO;
        }

        if (DecimalQuantity::compare($total, $esperado) < 0) {
            return NovedadTipo::FALTANTE;
        }

        if (DecimalQuantity::compare($total, $esperado) > 0) {
            return NovedadTipo::SOBRANTE;
        }

        if (DecimalQuantity::compare($aptos, $total) < 0) {
            return NovedadTipo::APTOS_INSUFICIENTES;
        }

        return NovedadTipo::OTRO;
    }

    /**
     * @return array<string, mixed>
     */
    private function datosNovedad(
        PedidoInventarioLinea $linea,
        PedidoRecepcionLinea $observacion,
        string $tipo,
        int $empleadoId,
    ): array {
        return [
            'pedido_linea_id' => (int) $linea->linea_id,
            'recepcion_linea_id' => (int) $observacion->recepcion_linea_id,
            'tipo' => $tipo,
            'motivo' => $this->motivoNovedad($observacion, $tipo),
            'esperado_snapshot' => (string) PedidoSnapshot::cantidadCanonica($linea->cantidad_esperada),
            'contado_snapshot' => (string) PedidoSnapshot::cantidadCanonica($observacion->conteo_total),
            'aptos_snapshot' => (string) PedidoSnapshot::cantidadCanonica($observacion->conteo_aptos),
            'estado' => NovedadEstado::ABIERTA,
            'responsable_empleado_id' => $empleadoId,
        ];
    }

    private function motivoNovedad(PedidoRecepcionLinea $observacion, string $tipo): string
    {
        $motivo = trim((string) $observacion->motivo);

        if ($motivo !== '') {
            return $motivo;
        }

        return match ($tipo) {
            NovedadTipo::FALTANTE => 'Faltante detectado en el conteo físico.',
            NovedadTipo::SOBRANTE => 'Sobrante detectado en el conteo físico.',
            NovedadTipo::DANADO => 'Mercancía dañada o no apta.',
            NovedadTipo::SKU_INCORRECTO => 'SKU distinto al esperado.',
            NovedadTipo::APTOS_INSUFICIENTES => 'Unidades no aptas para ingreso.',
            NovedadTipo::FICHA_INCOMPLETA => 'Ficha de producto nuevo incompleta.',
            default => 'Desviación detectada en la recepción.',
        };
    }

    private function cerrarLineaIngresada(PedidoInventarioLinea $linea, int $productoId): void
    {
        if ((int) $linea->producto_id !== $productoId) {
            $linea->producto_id = $productoId;
            $linea->save();
        }

        if (in_array((string) $linea->estado, [LineaEstado::PENDIENTE, LineaEstado::NOVEDAD], true)) {
            $linea->transicionarA(LineaEstado::CONFORME);
        }

        $linea->transicionarA(LineaEstado::RECIBIDA);
    }

    /**
     * Cierra la línea origen de un saldo abierto (U10): ya ingresó Q = A con
     * su único movimiento definitivo (R08 por línea-hija); el remanente vive
     * en la hija. Terminal para esta línea, nunca se reabre.
     */
    private function cerrarLineaParcial(PedidoInventarioLinea $linea, int $productoId): void
    {
        if ((int) $linea->producto_id !== $productoId) {
            $linea->producto_id = $productoId;
            $linea->save();
        }

        if (in_array((string) $linea->estado, [LineaEstado::PENDIENTE, LineaEstado::NOVEDAD], true)) {
            $linea->transicionarA(LineaEstado::CONFORME);
        }

        $linea->transicionarA(LineaEstado::RECIBIDA_PARCIAL);
    }

    /**
     * Divide un faltante puro (U10): cierra la origen como recibida_parcial,
     * resuelve su novedad abierta si existe y abre el saldo D en una hija
     * vinculada (pendiente, receivable sin reingreso de ficha) con novedad
     * propia. Todo ocurre dentro de la transacción de la confirmación.
     *
     * @return array{linea_id: int, hija_linea_id: int, hija_issue_id: int, cantidad_ingresada: string, saldo_restante: string, novedad_origen_id: int|null}
     */
    private function dividirSaldoAbierto(
        PedidoInventarioLinea $linea,
        PedidoRecepcionLinea $observacion,
        Collection $novedades,
        int $productoId,
        string $cantidadIngresada,
        string $saldo,
        int $ordenHija,
        int $empleadoId,
    ): array {
        $this->cerrarLineaParcial($linea, $productoId);

        $novedadOrigen = $novedades->first(fn (PedidoNovedad $novedad): bool => (int) $novedad->pedido_linea_id === (int) $linea->linea_id
            && (string) $novedad->estado === NovedadEstado::ABIERTA);

        $novedadOrigenId = null;

        if ($novedadOrigen !== null) {
            $novedadOrigen->transicionarA(NovedadEstado::RESUELTA, ['cerrada_at' => now()]);
            $novedadOrigenId = (int) $novedadOrigen->novedad_id;
        }

        $hija = PedidoInventarioLinea::create([
            'pedido_id' => (int) $linea->pedido_id,
            'parent_linea_id' => (int) $linea->linea_id,
            'orden' => $ordenHija,
            'sku_raw' => $linea->sku_raw,
            'sku_confirmado' => $linea->sku_confirmado,
            'descripcion_raw' => $linea->descripcion_raw,
            'descripcion_confirmada' => $linea->descripcion_confirmada,
            'cantidad_esperada' => $saldo,
            'unidad' => $linea->unidad,
            'producto_id' => $productoId,
            'categoria_propuesta_id' => $linea->categoria_propuesta_id,
            'alerta_minima_propuesta' => PedidoSnapshot::cantidadCanonica($linea->alerta_minima_propuesta),
            'precio_costo_confirmado' => PedidoSnapshot::cantidadCanonica($linea->precio_costo_confirmado),
            'moneda' => $linea->moneda,
            'base_precio' => $linea->base_precio,
            'estado' => LineaEstado::PENDIENTE,
            'esperado_congelado_at' => now(),
        ]);

        // La hija aún no tiene conteo propio (su observación nace en la
        // próxima sesión): la novedad refleja saldo pendiente por recibir.
        $novedadHija = PedidoNovedad::create([
            'pedido_linea_id' => (int) $hija->linea_id,
            'recepcion_linea_id' => null,
            'tipo' => NovedadTipo::FALTANTE,
            'motivo' => sprintf(
                'Saldo abierto tras ingreso parcial de %s sobre %s esperadas (línea origen #%d).',
                $cantidadIngresada,
                (string) PedidoSnapshot::cantidadCanonica($linea->cantidad_esperada),
                (int) $linea->linea_id,
            ),
            'esperado_snapshot' => $saldo,
            'contado_snapshot' => '0.00',
            'aptos_snapshot' => '0.00',
            'estado' => NovedadEstado::ABIERTA,
            'responsable_empleado_id' => $empleadoId,
            'abierta_at' => now(),
        ]);

        return [
            'linea_id' => (int) $linea->linea_id,
            'hija_linea_id' => (int) $hija->linea_id,
            'hija_issue_id' => (int) $novedadHija->novedad_id,
            'cantidad_ingresada' => $cantidadIngresada,
            'saldo_restante' => $saldo,
            'novedad_origen_id' => $novedadOrigenId,
        ];
    }

    /**
     * Cierra una línea con desfase autorizado: el estado terminal registra que
     * la excepción fue firmada, nunca que la cantidad esperada se completó.
     */
    private function cerrarLineaConDesfase(PedidoInventarioLinea $linea, int $productoId): void
    {
        if ((int) $linea->producto_id !== $productoId) {
            $linea->producto_id = $productoId;
            $linea->save();
        }

        if ((string) $linea->estado === LineaEstado::PENDIENTE) {
            $linea->transicionarA(LineaEstado::CONFORME);
        }

        $linea->transicionarA(LineaEstado::RECIBIDA_CON_DESFASE);
    }

    /**
     * Cierre autorizado con A = 0: cero movimientos, jamás una transacción de
     * cantidad cero (R08/R10).
     */
    private function cerrarLineaSinIngreso(PedidoInventarioLinea $linea): void
    {
        if (in_array((string) $linea->estado, [LineaEstado::PENDIENTE, LineaEstado::CONFORME], true)) {
            if ((string) $linea->estado === LineaEstado::PENDIENTE) {
                $linea->transicionarA(LineaEstado::CONFORME);
            }

            $linea->transicionarA(LineaEstado::NOVEDAD);
        }

        $linea->transicionarA(LineaEstado::CIERRE_SIN_INGRESO);
    }

    /**
     * Marca la firma como invalidada y lo audita (R12). El contenido de la
     * autorización es inmutable: solo cambia el estado.
     */
    private function invalidarAutorizacion(
        PedidoAutorizacion $autorizacion,
        PedidoNovedad $novedad,
        string $motivo,
        User $actor,
        string $operationId,
        PedidoInventario $orden,
    ): void {
        if (! $autorizacion->estaVigente()) {
            return;
        }

        $autorizacion->estado = PedidoAutorizacion::ESTADO_INVALIDADA;
        $autorizacion->save();

        $this->registrarEvento(
            (int) $orden->pedido_id,
            (int) $novedad->pedido_linea_id,
            self::EVENTO_DESFASE_INVALIDADO,
            $actor,
            [
                'autorizacion_id' => (int) $autorizacion->autorizacion_id,
                'estado' => PedidoAutorizacion::ESTADO_VIGENTE,
                'contexto_hash' => (string) $autorizacion->contexto_hash,
            ],
            [
                'autorizacion_id' => (int) $autorizacion->autorizacion_id,
                'estado' => PedidoAutorizacion::ESTADO_INVALIDADA,
                'motivo' => $motivo,
                'novedad_id' => (int) $novedad->novedad_id,
            ],
            $operationId,
            $orden->versionActual(),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function cantidadDeIngreso(array $item): string
    {
        if (($item['cantidad_ingreso'] ?? null) !== null) {
            return (string) $item['cantidad_ingreso'];
        }

        return (string) PedidoSnapshot::cantidadCanonica($item['linea']->cantidad_esperada);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<int>
     */
    private function idsDeAutorizaciones(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (($item['autorizacion'] ?? null) instanceof PedidoAutorizacion) {
                $ids[] = (int) $item['autorizacion']->autorizacion_id;
            }
        }

        return $ids;
    }

    private function rechazoLineaNoEditable(PedidoInventarioLinea $linea, string $accion): OperacionRechazadaException
    {
        if (LineaEstado::esTerminal((string) $linea->estado)) {
            return new OperacionRechazadaException(
                409,
                'La línea ya está cerrada: una entrega tardía no reabre la recepción, requiere un pedido complementario vinculado.',
                [
                    'linea_id' => (int) $linea->linea_id,
                    'linea_terminal' => true,
                    'requiere_pedido_complementario' => true,
                ],
            );
        }

        return new OperacionRechazadaException(
            409,
            "La línea ya tiene un ingreso definitivo y no admite {$accion}.",
            ['linea_id' => (int) $linea->linea_id],
        );
    }

    private function confirmarObservacion(
        PedidoRecepcionLinea $observacion,
        PedidoRecepcion $recepcion,
    ): void {
        if ((int) $observacion->recepcion_id === (int) $recepcion->recepcion_id
            && ! $observacion->count_confirmed) {
            $observacion->count_confirmed = true;
            $observacion->save();
        }
    }

    /**
     * Conserva el conteo anterior en `snapshots.historial` (R05/R06): un
     * reconteo reemplaza la observación vigente, nunca la suma.
     */
    private function registrarHistorial(PedidoRecepcionLinea $observacion): void
    {
        if ($observacion->getOriginal('conteo_total') === null) {
            return;
        }

        $snapshots = $observacion->snapshots ?? [];
        $historial = is_array($snapshots['historial'] ?? null) ? $snapshots['historial'] : [];

        $historial[] = [
            'conteo_total' => PedidoSnapshot::cantidadCanonica($observacion->getOriginal('conteo_total')),
            'conteo_aptos' => PedidoSnapshot::cantidadCanonica($observacion->getOriginal('conteo_aptos')),
            'estado_condicion' => $observacion->getOriginal('estado_condicion'),
            'motivo' => $observacion->getOriginal('motivo'),
            'observacion_version' => (int) $observacion->getOriginal('observacion_version'),
            'actor_user_id' => $observacion->getOriginal('actor_user_id'),
            'receptor_empleado_id' => $observacion->getOriginal('receptor_empleado_id'),
            'registrado_at' => $observacion->updated_at?->toIso8601String(),
        ];

        $snapshots['historial'] = $historial;
        $observacion->snapshots = $snapshots;
    }

    private function fichaCompleta(PedidoInventarioLinea $linea): bool
    {
        if ($linea->categoria_propuesta_id === null
            || trim((string) $linea->unidad) === ''
            || trim((string) $linea->descripcion_confirmada) === '') {
            return false;
        }

        // La categoría debe seguir existiendo al confirmar: nunca se crea una
        // categoría nueva desde la recepción.
        return Categoria::query()->whereKey($linea->categoria_propuesta_id)->exists();
    }

    private function firmaFicha(PedidoInventarioLinea $linea): string
    {
        return implode('|', [
            (string) (int) $linea->categoria_propuesta_id,
            mb_strtoupper(trim((string) $linea->unidad)),
            mb_strtoupper(trim((string) $linea->descripcion_confirmada)),
            (string) PedidoSnapshot::cantidadCanonica($linea->alerta_minima_propuesta),
            (string) PedidoSnapshot::cantidadCanonica($linea->precio_costo_confirmado),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fichaSnapshot(PedidoInventarioLinea $linea): array
    {
        return [
            'categoria_propuesta_id' => $linea->categoria_propuesta_id,
            'alerta_minima_propuesta' => PedidoSnapshot::cantidadCanonica($linea->alerta_minima_propuesta),
            'precio_costo_confirmado' => PedidoSnapshot::cantidadCanonica($linea->precio_costo_confirmado),
            'unidad' => $linea->unidad,
            'version' => $linea->versionActual(),
        ];
    }

    private function empleadoActivo(User $actor): Empleado
    {
        $empleado = $actor->relationLoaded('empleado')
            ? $actor->empleado
            : $actor->empleado()->first();

        if ($empleado === null || (string) $empleado->estado !== 'activo') {
            throw new OperacionRechazadaException(
                403,
                'La cuenta no tiene un empleado activo vinculado para operar la recepción.',
            );
        }

        return $empleado;
    }

    /**
     * IDs de línea con un ingreso definitivo en el ledger.
     *
     * @param  list<int>  $lineaIds
     * @return list<int>
     */
    private function lineasConIngreso(array $lineaIds): array
    {
        if ($lineaIds === []) {
            return [];
        }

        return TransaccionInventario::query()
            ->whereIn('pedido_linea_id', $lineaIds)
            ->pluck('pedido_linea_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Una sesión que no está en borrador no admite conteos ni confirmaciones:
     * confirmada es inmutable (R11) e invalidada pertenece a una revisión
     * anterior (retiro documental). Nunca se borra: es historial (R15).
     */
    private function rechazoSesionNoOperable(PedidoRecepcion $recepcion): OperacionRechazadaException
    {
        if ((string) $recepcion->estado === RecepcionEstado::INVALIDADA) {
            return new OperacionRechazadaException(
                409,
                'La sesión de recepción fue invalidada por una revisión documental; abre una sesión nueva.',
                ['recepcion_invalidada' => true],
            );
        }

        return new OperacionRechazadaException(
            409,
            'La recepción ya fue confirmada; no admite una segunda confirmación.',
            ['recepcion_id' => (int) $recepcion->recepcion_id],
        );
    }

    private function esViolacionUnicaSkuProducto(QueryException $exception): bool
    {
        $codigo = (string) $exception->getCode();
        $mensaje = $exception->getMessage();

        $esUnica = in_array($codigo, ['23000', '23505'], true)
            || str_contains($mensaje, 'UNIQUE constraint failed')
            || str_contains($mensaje, 'Duplicate entry');

        return $esUnica && str_contains($mensaje, 'producto_sku');
    }

    /**
     * @param  array<string, mixed>|null  $antes
     * @param  array<string, mixed>  $despues
     */
    private function registrarEvento(
        int $pedidoId,
        ?int $lineaId,
        string $tipo,
        User $actor,
        ?array $antes,
        array $despues,
        ?string $commandId,
        ?int $revision,
    ): PedidoEvento {
        return PedidoEvento::create([
            'pedido_id' => $pedidoId,
            'pedido_linea_id' => $lineaId,
            'event_type' => $tipo,
            'actor_user_id' => (int) $actor->id,
            'actor_rol_snapshot' => (string) $actor->role,
            'datos_antes' => $antes,
            'datos_despues' => $despues,
            'command_id' => $commandId,
            'revision' => $revision,
        ]);
    }
}
