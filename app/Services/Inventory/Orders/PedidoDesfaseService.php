<?php

namespace App\Services\Inventory\Orders;

use App\Domain\InventoryOrders\AlertaTipo;
use App\Domain\InventoryOrders\CondicionLinea;
use App\Domain\InventoryOrders\LineaEstado;
use App\Domain\InventoryOrders\NovedadEstado;
use App\Domain\InventoryOrders\OperacionRechazadaException;
use App\Domain\InventoryOrders\PedidoEstado;
use App\Domain\InventoryOrders\RecepcionEstado;
use App\Domain\InventoryOrders\TextoResponsabilidadDesfase;
use App\DTO\InventoryOrders\DecimalQuantity;
use App\Models\Empleado;
use App\Models\PedidoAutorizacion;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoNovedad;
use App\Models\PedidoRecepcion;
use App\Models\PedidoRecepcionLinea;
use App\Models\User;
use App\Policies\PedidoInventarioPolicy;
use App\Services\Inventory\Orders\Concerns\GestionaOperacionesPedido;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * Firma de desfase (U7; D1/D3, plan secciones 5, 9 y 11).
 *
 * - D1: firma el auxiliar de bodega (config), con empleado activo y la
 *   capacidad `pedidos.discrepancy.authorize`; el actor sale SOLO del auth.
 * - D3: SOLO CANTIDADES. Se registra Q = A y la diferencia Q - E; los campos
 *   monetarios (`importe`, `precio_unitario_confirmado`, `moneda`,
 *   `base_precio`) permanecen NULL y el request los prohíbe.
 * - Firmar NO mueve stock: la firma queda `vigente` y se consume dentro de la
 *   confirmación que ingresa Q. `confirmar_inmediato` ejecuta esa confirmación
 *   atómica (misma transacción) reutilizando el camino de U6 sin saltar una
 *   sola validación.
 * - Cualquier cambio de contexto invalida la firma al confirmar (R12).
 */
class PedidoDesfaseService
{
    use GestionaOperacionesPedido;

    public const COMMAND_AUTHORIZE = 'autorizar_desfase';

    public const EVENTO_AUTORIZADO = 'desfase_autorizado';

    public const EVENTO_INVALIDADO = 'desfase_invalidado';

    /** @var list<string> */
    public const ESTADOS_FIRMABLES = [
        PedidoEstado::EN_VALIDACION,
        PedidoEstado::PARCIAL_CON_NOVEDAD,
    ];

    public function __construct(
        private readonly PedidoAlertaService $alertas,
        private readonly PedidoRecepcionService $recepcion,
    ) {}

    /**
     * @return array{operacion: ?\App\Models\PedidoOperacion, replay: bool, payload: array<string, mixed>}
     */
    public function autorizar(
        PedidoInventario $pedido,
        int $novedadId,
        User $actor,
        string $motivo,
        bool $confirmarInmediato,
        ?string $operationId = null,
    ): array {
        $empleado = $this->empleadoActivo($actor);
        $this->assertPuedeAutorizar($actor);

        $hash = $operationId !== null
            ? $this->hashOperacionPedido([
                'pedido_uuid' => $pedido->uuid,
                'command' => self::COMMAND_AUTHORIZE,
                'novedad_id' => $novedadId,
                'motivo' => $motivo,
                'confirmar_inmediato' => $confirmarInmediato,
            ])
            : '';

        if ($operationId !== null
            && ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND_AUTHORIZE, $operationId))) {
            return $this->replay($existente, $hash);
        }

        $resultado = DB::transaction(function () use (
            $pedido,
            $novedadId,
            $actor,
            $empleado,
            $motivo,
            $confirmarInmediato,
            $operationId,
            $hash,
        ): array {
            if ($operationId !== null
                && ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND_AUTHORIZE, $operationId, true))) {
                return $this->replay($existente, $hash);
            }

            $orden = PedidoInventario::query()
                ->whereKey($pedido->pedido_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array((string) $orden->estado, self::ESTADOS_FIRMABLES, true)) {
                throw new OperacionRechazadaException(
                    409,
                    'El pedido no admite firmas de desfase en su estado actual.',
                    ['estado' => (string) $orden->estado],
                );
            }

            // Orden determinista: pedido -> línea -> recepción vigente ->
            // novedad -> observación (mismo orden que la confirmación).
            $referencia = PedidoNovedad::query()->whereKey($novedadId)->first();

            if ($referencia === null) {
                throw new OperacionRechazadaException(404, 'La novedad no existe.');
            }

            $linea = PedidoInventarioLinea::query()
                ->whereKey($referencia->pedido_linea_id)
                ->where('pedido_id', $orden->pedido_id)
                ->lockForUpdate()
                ->first();

            if ($linea === null) {
                throw new OperacionRechazadaException(404, 'La novedad no pertenece a este pedido.');
            }

            $novedad = PedidoNovedad::query()
                ->whereKey($novedadId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $novedad->estado !== NovedadEstado::ABIERTA) {
                throw new OperacionRechazadaException(
                    409,
                    'La novedad ya no está abierta y no admite una firma de desfase.',
                    ['novedad_id' => (int) $novedad->novedad_id, 'estado' => (string) $novedad->estado],
                );
            }

            if (LineaEstado::esTerminal((string) $linea->estado)) {
                throw new OperacionRechazadaException(
                    409,
                    'La línea ya está cerrada: una entrega tardía requiere un pedido complementario vinculado.',
                    ['requiere_pedido_complementario' => true],
                );
            }

            $recepcionVigente = PedidoRecepcion::query()
                ->where('pedido_id', $orden->pedido_id)
                ->where('estado', RecepcionEstado::BORRADOR)
                ->orderByDesc('recepcion_id')
                ->lockForUpdate()
                ->first();

            $observacion = $this->observacionEfectiva($novedad, $linea, $recepcionVigente);

            if ($observacion === null || $observacion->conteo_total === null || $observacion->conteo_aptos === null) {
                throw new OperacionRechazadaException(
                    409,
                    'La novedad no tiene un conteo vigente; registra un conteo antes de firmar.',
                );
            }

            $esperado = (string) PedidoSnapshot::cantidadCanonica($linea->cantidad_esperada);
            $contado = (string) PedidoSnapshot::cantidadCanonica($observacion->conteo_total);
            $aptos = (string) PedidoSnapshot::cantidadCanonica($observacion->conteo_aptos);

            $this->assertFirmable($observacion, $esperado, $contado, $aptos);

            $contexto = DesfaseContexto::capturar($orden, $linea, $observacion);
            $contextoHash = DesfaseContexto::hash($contexto);

            $reemplazadas = PedidoAutorizacion::query()
                ->where('novedad_id', $novedad->novedad_id)
                ->where('estado', PedidoAutorizacion::ESTADO_VIGENTE)
                ->orderBy('autorizacion_id')
                ->lockForUpdate()
                ->get();

            foreach ($reemplazadas as $previa) {
                $previa->estado = PedidoAutorizacion::ESTADO_INVALIDADA;
                $previa->save();

                $this->registrarEvento(
                    (int) $orden->pedido_id,
                    (int) $linea->linea_id,
                    self::EVENTO_INVALIDADO,
                    $actor,
                    ['autorizacion_id' => (int) $previa->autorizacion_id, 'estado' => PedidoAutorizacion::ESTADO_VIGENTE],
                    [
                        'autorizacion_id' => (int) $previa->autorizacion_id,
                        'estado' => PedidoAutorizacion::ESTADO_INVALIDADA,
                        'motivo' => 'reemplazada_por_nueva_firma',
                        'novedad_id' => (int) $novedad->novedad_id,
                    ],
                    $operationId,
                    $orden->versionActual(),
                );
            }

            $diferencia = bcsub($aptos, $esperado, DecimalQuantity::LEDGER_SCALE);

            $autorizacion = PedidoAutorizacion::create([
                'novedad_id' => (int) $novedad->novedad_id,
                'actor_user_id' => (int) $actor->id,
                'empleado_id' => (int) $empleado->id,
                'rol_snapshot' => strtolower(trim((string) $actor->role)),
                'cantidad_autorizada' => $aptos,
                'order_version' => $orden->versionActual(),
                'line_version' => $linea->versionActual(),
                'count_revision' => (int) $observacion->observacion_version,
                'contexto_hash' => $contextoHash,
                'diferencia_cantidad' => $diferencia,
                'motivo' => $motivo,
                'aceptacion_responsabilidad' => TextoResponsabilidadDesfase::conVersion(),
                'aceptada_at' => now(),
                'estado' => PedidoAutorizacion::ESTADO_VIGENTE,
                // D3: SOLO CANTIDADES. Los campos monetarios permanecen NULL.
                'importe' => null,
                'precio_unitario_confirmado' => null,
                'moneda' => null,
                'base_precio' => null,
            ]);

            $evento = $this->registrarEvento(
                (int) $orden->pedido_id,
                (int) $linea->linea_id,
                self::EVENTO_AUTORIZADO,
                $actor,
                $reemplazadas->isEmpty() ? null : [
                    'autorizaciones_reemplazadas' => $reemplazadas
                        ->map(fn (PedidoAutorizacion $previa): int => (int) $previa->autorizacion_id)
                        ->all(),
                ],
                [
                    'autorizacion_id' => (int) $autorizacion->autorizacion_id,
                    'novedad_id' => (int) $novedad->novedad_id,
                    'linea_id' => (int) $linea->linea_id,
                    'cantidad_autorizada' => $aptos,
                    'diferencia_cantidad' => $diferencia,
                    'esperado' => $esperado,
                    'contado' => $contado,
                    'aptos' => $aptos,
                    'estado_condicion' => (string) $observacion->estado_condicion,
                    'tipo_novedad' => (string) $novedad->tipo,
                    'contexto_hash' => $contextoHash,
                    'order_version' => $contexto['order_version'],
                    'line_version' => $contexto['line_version'],
                    'observacion_version' => $contexto['observacion_version'],
                    'rol_snapshot' => $autorizacion->rol_snapshot,
                    'responsabilidad_version' => TextoResponsabilidadDesfase::VERSION,
                    'moneda' => null,
                ],
                $operationId,
                $orden->versionActual(),
            );

            $this->alertas->crearParaEvento(
                $evento,
                AlertaTipo::DESFASE_AUTORIZADO,
                $this->alertas->destinatariosDesfase($orden, $actor),
            );

            $payload = [
                'autorizacion' => $this->autorizacionPayload($autorizacion, $novedad, $linea),
                'confirmacion' => null,
            ];

            if ($confirmarInmediato) {
                $payload['confirmacion'] = $this->confirmarEnMismaTransaccion(
                    $orden,
                    $actor,
                    $operationId,
                );
            }

            return [
                'operacion' => $operationId !== null
                    ? $this->registrarOperacionPedido(
                        (int) $actor->id,
                        self::COMMAND_AUTHORIZE,
                        $operationId,
                        $hash,
                        (string) $orden->uuid,
                        $payload,
                    )
                    : null,
                'replay' => false,
                'payload' => $payload,
            ];
        }, PedidoRecepcionService::INTENTOS_TX);

        return $resultado;
    }

    /**
     * Reutiliza el camino atómico de U6: abre/reanuda la sesión de recepción y
     * confirma con las MISMAS validaciones, locks y observer. La firma recién
     * creada se consume dentro de la misma transacción.
     *
     * @return array<string, mixed>
     */
    private function confirmarEnMismaTransaccion(
        PedidoInventario $orden,
        User $actor,
        ?string $operationId,
    ): array {
        $resolucion = $this->recepcion->crearOReanudar($orden, $actor);
        $recepcion = $resolucion['recepcion'];

        $version = (int) PedidoRecepcion::query()
            ->whereKey($recepcion->recepcion_id)
            ->value('version');

        $confirmacion = $this->recepcion->confirmar(
            $orden,
            $recepcion,
            $actor,
            $version,
            $operationId ?? (string) Str::uuid(),
        );

        return is_array($confirmacion['operacion']->resultado ?? null)
            ? $confirmacion['operacion']->resultado
            : [];
    }

    /**
     * Observación que rige la firma: el conteo de la sesión vigente si existe;
     * si no, el conteo que abrió la novedad.
     */
    private function observacionEfectiva(
        PedidoNovedad $novedad,
        PedidoInventarioLinea $linea,
        ?PedidoRecepcion $recepcionVigente,
    ): ?PedidoRecepcionLinea {
        if ($recepcionVigente !== null) {
            $sesion = PedidoRecepcionLinea::query()
                ->where('recepcion_id', $recepcionVigente->recepcion_id)
                ->where('pedido_linea_id', $linea->linea_id)
                ->lockForUpdate()
                ->first();

            if ($sesion !== null && $sesion->conteo_total !== null) {
                return $sesion;
            }
        }

        if ($novedad->recepcion_linea_id === null) {
            return null;
        }

        return PedidoRecepcionLinea::query()
            ->whereKey($novedad->recepcion_linea_id)
            ->lockForUpdate()
            ->first();
    }

    private function assertFirmable(
        PedidoRecepcionLinea $observacion,
        string $esperado,
        string $contado,
        string $aptos,
    ): void {
        $condicion = (string) $observacion->estado_condicion;

        if (in_array($condicion, [CondicionLinea::DANADO, CondicionLinea::SKU_INCORRECTO], true)) {
            throw new OperacionRechazadaException(
                422,
                'La condición registrada requiere corrección documental: la firma de desfase solo cubre diferencias de cantidad.',
                ['estado_condicion' => $condicion],
            );
        }

        if (DecimalQuantity::compare($aptos, $contado) > 0) {
            throw new OperacionRechazadaException(
                422,
                'La cantidad apta no puede superar la cantidad contada.',
                ['conteo_total' => $contado, 'conteo_aptos' => $aptos],
            );
        }

        if (DecimalQuantity::compare($aptos, $esperado) > 0) {
            throw new OperacionRechazadaException(
                422,
                'Hay sobrante sobre el esperado: excluye los sobrantes o cuenta de nuevo antes de aceptar.',
                ['cantidad_esperada' => $esperado, 'conteo_aptos' => $aptos],
            );
        }

        if (DecimalQuantity::compare($aptos, '0.00') === 0
            && ! (bool) config('inventory_orders.cierre_sin_ingreso_permitido', false)) {
            throw new OperacionRechazadaException(
                422,
                'El cierre sin ingreso no está habilitado: la novedad permanece abierta hasta una decisión de política.',
                ['cierre_sin_ingreso_permitido' => false],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function autorizacionPayload(
        PedidoAutorizacion $autorizacion,
        PedidoNovedad $novedad,
        PedidoInventarioLinea $linea,
    ): array {
        return [
            'autorizacion_id' => (int) $autorizacion->autorizacion_id,
            'novedad_id' => (int) $novedad->novedad_id,
            'linea_id' => (int) $linea->linea_id,
            'estado' => (string) $autorizacion->estado,
            'cantidad_autorizada' => (string) PedidoSnapshot::cantidadCanonica($autorizacion->cantidad_autorizada),
            'diferencia_cantidad' => (string) $autorizacion->diferencia_cantidad,
            'contexto_hash' => (string) $autorizacion->contexto_hash,
            'rol_snapshot' => (string) $autorizacion->rol_snapshot,
            'aceptada_at' => $autorizacion->aceptada_at?->toISOString(),
            'responsabilidad_version' => TextoResponsabilidadDesfase::VERSION,
            'importe' => null,
            'precio_unitario_confirmado' => null,
            'moneda' => null,
            'base_precio' => null,
        ];
    }

    private function assertPuedeAutorizar(User $actor): void
    {
        if (! (bool) config('inventory_orders.discrepancy_authorization_enabled', false)) {
            throw new OperacionRechazadaException(
                403,
                'D1: la firma de desfase está deshabilitada por configuración.',
            );
        }

        if (! PedidoInventarioPolicy::rolePuedeAutorizarDesfase((string) $actor->role)) {
            throw new OperacionRechazadaException(
                403,
                'El rol autenticado no está habilitado para firmar desfases.',
            );
        }
    }

    private function empleadoActivo(User $actor): Empleado
    {
        $empleado = $actor->relationLoaded('empleado')
            ? $actor->empleado
            : $actor->empleado()->first();

        if ($empleado === null || (string) $empleado->estado !== 'activo') {
            throw new OperacionRechazadaException(
                403,
                'La cuenta no tiene un empleado activo vinculado para firmar el desfase.',
            );
        }

        return $empleado;
    }

    /**
     * @return array{operacion: ?\App\Models\PedidoOperacion, replay: true, payload: array<string, mixed>}
     */
    private function replay(\App\Models\PedidoOperacion $operacion, string $hash): array
    {
        $base = $this->replayOperacionPedido($operacion, $hash);

        return [
            'operacion' => $base['operacion'],
            'replay' => true,
            'payload' => is_array($operacion->resultado) ? $operacion->resultado : [],
        ];
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
