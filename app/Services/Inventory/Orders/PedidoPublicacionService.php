<?php

namespace App\Services\Inventory\Orders;

use App\Domain\InventoryOrders\AlertaTipo;
use App\Domain\InventoryOrders\OperacionRechazadaException;
use App\Domain\InventoryOrders\PedidoEstado;
use App\DTO\InventoryOrders\DecimalQuantity;
use App\Models\PedidoDocumento;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\User;
use App\Services\Inventory\Orders\Concerns\GestionaOperacionesPedido;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publicación de pedidos (plan secciones 5, 9 y 10; R02/R03/R17).
 *
 * Puertas (todas en una transacción y bajo lock de la cabecera):
 * - capacidad/estado los garantiza la Policy; el servicio revalida estado y
 *   versión bajo lock para cerrar la carrera entre check y efecto.
 * - proveedor confirmado, >=1 línea revisada válida, pantallazo Siigo y factura
 *   vigentes, bodega válida y >=1 receptor activo con pedidos.receive.
 *
 * Deduplicación documental:
 * - Identidad fiable (proveedor_clave + referencia_factura) reserva la clave
 *   normalizada única; colisión con otro pedido = 409 con la referencia ajena.
 * - Sin identidad fiable se bloquea por sospecha (sha256 repetido o proveedor +
 *   fecha de creación + línea confirmada idéntica) salvo motivo de compra
 *   distinta (>= 10 caracteres), que queda auditado en el evento.
 *
 * Efectos: estado pendiente_recepcion, número interno, published_at,
 * submitted_by, congelamiento del esperado, version++, evento
 * `pedido_publicado` (outbox pendiente para U8) y una alerta por receptor.
 * CERO movimientos de inventario: esta unidad nunca toca el ledger.
 */
class PedidoPublicacionService
{
    use GestionaOperacionesPedido;

    public const COMMAND = 'publicar_pedido';

    public const EVENTO_PUBLICADO = 'pedido_publicado';

    public const LONGITUD_CLAVE_DOCUMENTAL = 190;

    public const MOTIVO_COMPRA_DISTINTA_MIN = 10;

    public function __construct(
        private readonly PedidoAlertaService $alertas,
    ) {}

    /**
     * @return array{operacion: \App\Models\PedidoOperacion, replay: bool}
     */
    public function publicar(
        PedidoInventario $pedido,
        User $actor,
        int $versionEsperada,
        string $operationId,
        ?string $motivoCompraDistinta = null,
    ): array {
        $hash = $this->hashOperacionPedido([
            'pedido_uuid' => $pedido->uuid,
            'command' => self::COMMAND,
            'version' => $versionEsperada,
            'motivo_compra_distinta' => $motivoCompraDistinta,
        ]);

        if ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND, $operationId)) {
            return $this->replayOperacionPedido($existente, $hash);
        }

        try {
            $resultado = DB::transaction(function () use ($pedido, $actor, $versionEsperada, $operationId, $motivoCompraDistinta, $hash): array {
                if ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND, $operationId, true)) {
                    return $this->replayOperacionPedido($existente, $hash);
                }

                $bloqueado = PedidoInventario::query()
                    ->whereKey($pedido->pedido_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((string) $bloqueado->estado !== PedidoEstado::BORRADOR) {
                    throw new OperacionRechazadaException(
                        409,
                        'El pedido ya no está en borrador y no puede publicarse.',
                    );
                }

                $bloqueado->assertVersion($versionEsperada);
                $bloqueado->load(['lineas', 'documentos']);

        $receptores = $this->validarPuertas($bloqueado);

        $clave = $this->claveDocumental($bloqueado);
        $sospechas = [];

        if ($clave !== null) {
            $this->verificarClaveDisponible($bloqueado, $clave);
        } else {
            $sospechas = $this->detectarSospechas($bloqueado);

            if ($sospechas !== [] && ! $this->motivoCompraDistintaValido($motivoCompraDistinta)) {
                throw new OperacionRechazadaException(
                    409,
                    'Posible compra duplicada: hay coincidencias documentales con otros pedidos. '
                    .'Confirma el motivo de compra distinta (mínimo '.self::MOTIVO_COMPRA_DISTINTA_MIN.' caracteres) para continuar.',
                    [
                        'candidatos' => $sospechas,
                        'requiere_motivo_compra_distinta' => true,
                    ],
                );
            }
        }

                $antes = PedidoSnapshot::capturar($bloqueado);
                $numero = $bloqueado->numero ?? $this->numeroInterno($bloqueado);

                $bloqueado->numero = $numero;
                $bloqueado->estado = PedidoEstado::PENDIENTE_RECEPCION;
                $bloqueado->published_at = now();
                $bloqueado->submitted_by = $actor->id;

                if ($clave !== null) {
                    $bloqueado->clave_documental_normalizada = $clave;
                }

                $bloqueado->incrementarVersion();
                $bloqueado->save();

                // R03: el esperado queda congelado para todas las líneas.
                PedidoInventarioLinea::query()
                    ->where('pedido_id', $bloqueado->pedido_id)
                    ->update([
                        'esperado_congelado_at' => now(),
                        'updated_at' => now(),
                    ]);

                $evento = PedidoEvento::create([
                    'pedido_id' => $bloqueado->pedido_id,
                    'pedido_linea_id' => null,
                    'event_type' => self::EVENTO_PUBLICADO,
                    'actor_user_id' => $actor->id,
                    'actor_rol_snapshot' => (string) $actor->role,
                    'datos_antes' => $antes,
                    'datos_despues' => [
                        'estado' => PedidoEstado::PENDIENTE_RECEPCION,
                        'numero' => $numero,
                        'version' => $bloqueado->versionActual(),
                        'clave_documental_normalizada' => $clave,
                        'lineas_congeladas' => $bloqueado->lineas->count(),
                        'receptores' => $receptores->pluck('id')->all(),
                        'motivo_compra_distinta' => $clave === null ? $motivoCompraDistinta : null,
                        'sospechas_omitidas' => $clave === null && $this->motivoCompraDistintaValido($motivoCompraDistinta)
                            ? array_map(fn (array $c): ?string => $c['pedido_uuid'] ?? null, $sospechas)
                            : [],
                    ],
                    'command_id' => $operationId,
                    'revision' => $bloqueado->versionActual(),
                ]);

                $this->alertas->crearParaEvento($evento, AlertaTipo::PEDIDO_PUBLICADO, $receptores);

                $payload = [
                    'pedido_id' => $bloqueado->pedido_id,
                    'uuid' => $bloqueado->uuid,
                    'numero' => $numero,
                    'estado' => PedidoEstado::PENDIENTE_RECEPCION,
                    'version' => $bloqueado->versionActual(),
                    'identidad_documental_reservada' => $clave !== null,
                    'receptores_notificados' => $receptores->count(),
                    'motivo_compra_distinta' => $clave === null ? $motivoCompraDistinta : null,
                ];

                return [
                    'operacion' => $this->registrarOperacionPedido(
                        $actor->id,
                        self::COMMAND,
                        $operationId,
                        $hash,
                        $bloqueado->uuid,
                        $payload,
                    ),
                    'replay' => false,
                ];
            });
        } catch (QueryException $exception) {
            if ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND, $operationId)) {
                return $this->replayOperacionPedido($existente, $hash);
            }

            if ($this->esViolacionUnica($exception)) {
                $this->rechazarPorClaveGanadora($pedido);
            }

            throw $exception;
        }

        return $resultado;
    }

    /**
     * Precondiciones R02. Los datos faltantes responden 422 con un mensaje
     * distinto por puerta; la ausencia de receptor es un conflicto operativo 409.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function validarPuertas(PedidoInventario $pedido): \Illuminate\Database\Eloquent\Collection
    {
        $errores = [];

        if (trim((string) $pedido->proveedor_nombre_confirmado) === '') {
            $errores['proveedor_nombre_confirmado'] = 'Debes confirmar el nombre del proveedor antes de publicar.';
        }

        $lineasValidas = $pedido->lineas->filter(fn (PedidoInventarioLinea $linea): bool => $this->lineaValida($linea));

        if ($lineasValidas->isEmpty()) {
            $errores['lineas'] = 'Debes confirmar al menos una línea revisada con SKU, descripción y cantidad esperada mayor que cero.';
        }

        $documentos = $this->documentosVigentes($pedido);

        if (! $documentos->contains(fn (PedidoDocumento $documento): bool => (string) $documento->rol === PedidoDocumentoService::ROL_PANTALLAZO_SIIGO)) {
            $errores['documentos'] = 'Falta el pantallazo de Siigo vigente: no se puede publicar sin captura.';
        }

        if (! $documentos->contains(fn (PedidoDocumento $documento): bool => (string) $documento->rol === PedidoDocumentoService::ROL_FACTURA)) {
            $errores['factura'] = 'Falta la factura vigente: no se puede publicar sin factura.';
        }

        if ($pedido->bodega_id === null || ! $pedido->bodega()->exists()) {
            $errores['bodega_id'] = 'La bodega destino no es válida o no fue seleccionada explícitamente.';
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $receptores = $this->alertas->receptoresActivos();

        if ($receptores->isEmpty()) {
            throw new OperacionRechazadaException(
                409,
                'No hay un receptor de bodega activo con la capacidad pedidos.receive: sin receptor habilitado, publicar falla.',
            );
        }

        return $receptores;
    }

    private function lineaValida(PedidoInventarioLinea $linea): bool
    {
        $sku = trim((string) $linea->sku_confirmado);
        $descripcion = trim((string) $linea->descripcion_confirmada);
        $cantidad = PedidoSnapshot::cantidadCanonica($linea->cantidad_esperada);

        return $sku !== ''
            && $descripcion !== ''
            && $cantidad !== null
            && DecimalQuantity::compare($cantidad, '0.00') > 0;
    }

    /**
     * Documentos vigentes: los que no fueron reemplazados por una revisión
     * posterior del mismo rol.
     *
     * @return \Illuminate\Support\Collection<int, PedidoDocumento>
     */
    private function documentosVigentes(PedidoInventario $pedido): \Illuminate\Support\Collection
    {
        $documentos = $pedido->relationLoaded('documentos')
            ? $pedido->documentos
            : $pedido->documentos()->get();

        $reemplazados = $documentos
            ->pluck('replaced_document_id')
            ->filter()
            ->unique()
            ->all();

        return $documentos
            ->whereNotIn('documento_id', $reemplazados)
            ->values();
    }

    /**
     * Identidad documental fiable = proveedor_clave + referencia_factura
     * confirmados explícitamente. Sin ambos, no se reserva clave.
     */
    public function claveDocumental(PedidoInventario $pedido): ?string
    {
        $proveedor = trim((string) $pedido->proveedor_clave);
        $referencia = trim((string) $pedido->referencia_factura);

        if ($proveedor === '' || $referencia === '') {
            return null;
        }

        $clave = $this->normalizarIdentidad($proveedor).'|'.$this->normalizarIdentidad($referencia);

        if (mb_strlen($clave) > self::LONGITUD_CLAVE_DOCUMENTAL) {
            throw ValidationException::withMessages([
                'proveedor_clave' => 'La identidad documental (proveedor + factura) supera la longitud admitida.',
            ]);
        }

        return $clave;
    }

    private function verificarClaveDisponible(PedidoInventario $pedido, string $clave): void
    {
        $colision = PedidoInventario::query()
            ->where('clave_documental_normalizada', $clave)
            ->where('pedido_id', '!=', $pedido->pedido_id)
            ->first();

        if ($colision === null) {
            return;
        }

        throw new OperacionRechazadaException(
            409,
            'Documento duplicado: la misma identidad proveedor + factura ya está reservada por otro pedido.',
            ['candidatos' => [$this->candidatoPedido($colision, ['clave_documental'])], 'requiere_revision_humana' => true],
        );
    }

    private function rechazarPorClaveGanadora(PedidoInventario $pedido): void
    {
        $fresco = PedidoInventario::query()->whereKey($pedido->pedido_id)->first();
        $clave = $fresco !== null ? $this->claveDocumental($fresco) : null;

        if ($clave === null) {
            return;
        }

        $colision = PedidoInventario::query()
            ->where('clave_documental_normalizada', $clave)
            ->where('pedido_id', '!=', $pedido->pedido_id)
            ->first();

        if ($colision === null) {
            return;
        }

        throw new OperacionRechazadaException(
            409,
            'Documento duplicado: la misma identidad proveedor + factura ya está reservada por otro pedido.',
            ['candidatos' => [$this->candidatoPedido($colision, ['clave_documental'])], 'requiere_revision_humana' => true],
        );
    }

    /**
     * Sospecha de duplicado sin identidad fiable: sha256 repetido en documentos
     * de otros pedidos no cancelados y heurística liviana proveedor + fecha +
     * línea confirmada idéntica.
     *
     * @return list<array<string, mixed>>
     */
    public function detectarSospechas(PedidoInventario $pedido): array
    {
        $candidatos = [];

        foreach ([...$this->sospechaPorSha($pedido), ...$this->sospechaHeuristica($pedido)] as $candidato) {
            $id = $candidato['pedido_id'];

            if (! isset($candidatos[$id])) {
                $candidatos[$id] = $candidato;
                continue;
            }

            $candidatos[$id]['motivos'] = array_values(array_unique(array_merge(
                $candidatos[$id]['motivos'],
                $candidato['motivos'],
            )));
        }

        return array_values($candidatos);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sospechaPorSha(PedidoInventario $pedido): array
    {
        $shas = PedidoDocumento::query()
            ->where('pedido_id', $pedido->pedido_id)
            ->whereNotNull('sha256')
            ->pluck('sha256')
            ->unique();

        if ($shas->isEmpty()) {
            return [];
        }

        return PedidoDocumento::query()
            ->whereIn('sha256', $shas->all())
            ->where('pedido_id', '!=', $pedido->pedido_id)
            ->whereHas('pedido', fn ($query) => $query->where('estado', '!=', PedidoEstado::CANCELADO))
            ->with('pedido:pedido_id,uuid,numero,estado,proveedor_nombre_confirmado')
            ->get()
            ->map(function (PedidoDocumento $documento): ?array {
                $otro = $documento->pedido;

                if ($otro === null) {
                    return null;
                }

                return $this->candidatoPedido($otro, ['sha256_duplicado']);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sospechaHeuristica(PedidoInventario $pedido): array
    {
        $fecha = $pedido->created_at?->toDateString();
        $proveedor = $this->normalizarIdentidad((string) $pedido->proveedor_nombre_confirmado);

        if ($fecha === null || $proveedor === '') {
            return [];
        }

        $lineasPropias = $pedido->lineas
            ->map(fn (PedidoInventarioLinea $linea): ?string => $this->claveLinea($linea))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($lineasPropias === []) {
            return [];
        }

        return PedidoInventario::query()
            ->where('pedido_id', '!=', $pedido->pedido_id)
            ->where('estado', '!=', PedidoEstado::CANCELADO)
            ->whereDate('created_at', $fecha)
            ->with('lineas')
            ->get()
            ->filter(function (PedidoInventario $otro) use ($proveedor, $lineasPropias): bool {
                if ($this->normalizarIdentidad((string) $otro->proveedor_nombre_confirmado) !== $proveedor) {
                    return false;
                }

                $lineasAjenas = $otro->lineas
                    ->map(fn (PedidoInventarioLinea $linea): ?string => $this->claveLinea($linea))
                    ->filter()
                    ->unique()
                    ->all();

                return array_intersect($lineasPropias, $lineasAjenas) !== [];
            })
            ->map(fn (PedidoInventario $otro): array => $this->candidatoPedido($otro, ['proveedor_fecha_linea']))
            ->values()
            ->all();
    }

    private function claveLinea(PedidoInventarioLinea $linea): ?string
    {
        $sku = mb_strtoupper(trim((string) $linea->sku_confirmado));
        $cantidad = PedidoSnapshot::cantidadCanonica($linea->cantidad_esperada);

        if ($sku === '' || $cantidad === null) {
            return null;
        }

        return $sku.'|'.$cantidad;
    }

    /**
     * @param  list<string>  $motivos
     * @return array<string, mixed>
     */
    private function candidatoPedido(PedidoInventario $pedido, array $motivos): array
    {
        return [
            'pedido_id' => $pedido->pedido_id,
            'pedido_uuid' => $pedido->uuid,
            'numero' => $pedido->numero,
            'estado' => (string) $pedido->estado,
            'proveedor_nombre_confirmado' => $pedido->proveedor_nombre_confirmado,
            'motivos' => array_values(array_unique($motivos)),
        ];
    }

    public function motivoCompraDistintaValido(?string $motivo): bool
    {
        return $motivo !== null && mb_strlen(trim($motivo)) >= self::MOTIVO_COMPRA_DISTINTA_MIN;
    }

    public function numeroInterno(PedidoInventario $pedido): string
    {
        return sprintf('PED-%06d', $pedido->pedido_id);
    }

    /**
     * Trim + espacios colapsados + mayúsculas. No altera el valor persistido.
     */
    public function normalizarIdentidad(string $texto): string
    {
        $colapsado = preg_replace('/\s+/u', ' ', trim($texto));

        return mb_strtoupper($colapsado ?? trim($texto));
    }

    /**
     * Igual que SessionGuard/PDO code para SQLite y MySQL/MariaDB.
     */
    private function esViolacionUnica(QueryException $exception): bool
    {
        $codigo = (string) $exception->getCode();
        $mensaje = $exception->getMessage();

        return in_array($codigo, ['23000', '23505'], true)
            || str_contains($mensaje, 'UNIQUE constraint failed')
            || str_contains($mensaje, 'Duplicate entry');
    }
}
