<?php

namespace App\Services\Inventory\Orders;

use App\Domain\InventoryOrders\AlertaTipo;
use App\Domain\InventoryOrders\OperacionRechazadaException;
use App\Domain\InventoryOrders\PedidoEstado;
use App\Domain\InventoryOrders\RecepcionEstado;
use App\Models\PedidoAutorizacion;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoNovedad;
use App\Models\PedidoRecepcion;
use App\Models\TransaccionInventario;
use App\Models\User;
use App\Services\Inventory\Orders\Concerns\GestionaOperacionesPedido;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Retiro documental y cancelación (plan secciones 6, 9 y 11; R15).
 *
 * - Retirar (return-for-correction): solo desde pendiente_recepcion, sin
 *   recepción confirmada y sin ningún movimiento de ledger; devuelve el pedido
 *   a borrador, descongela el esperado para la nueva revisión, audita
 *   `pedido_retirado` y avisa a los destinatarios de la publicación previa.
 *   Re-publicar genera una revisión nueva, nunca un pedido duplicado.
 * - Cancelar: solo antes de cualquier ingreso (borrador, pendiente_recepcion,
 *   en_validacion) y sin movimientos; estado terminal `cancelado` con fecha y
 *   motivo. Nada se borra físicamente (R15).
 *
 * CERO escrituras de inventario en ambos flujos.
 */
class PedidoRetiroCancelacionService
{
    use GestionaOperacionesPedido;

    public const COMMAND_RETIRO = 'retirar_publicacion';

    public const COMMAND_CANCEL = 'cancelar_pedido';

    public const EVENTO_RETIRADO = 'pedido_retirado';

    public const EVENTO_CANCELADO = 'pedido_cancelado';

    /** @var list<string> */
    public const ESTADOS_CANCELABLES = [
        PedidoEstado::BORRADOR,
        PedidoEstado::PENDIENTE_RECEPCION,
        PedidoEstado::EN_VALIDACION,
    ];

    public function __construct(
        private readonly PedidoAlertaService $alertas,
    ) {}

    /**
     * @return array{operacion: \App\Models\PedidoOperacion, replay: bool}
     */
    public function retirar(
        PedidoInventario $pedido,
        User $actor,
        int $versionEsperada,
        string $motivo,
        string $operationId,
    ): array {
        $hash = $this->hashOperacionPedido([
            'pedido_uuid' => $pedido->uuid,
            'command' => self::COMMAND_RETIRO,
            'version' => $versionEsperada,
            'motivo' => $motivo,
        ]);

        if ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND_RETIRO, $operationId)) {
            return $this->replayOperacionPedido($existente, $hash);
        }

        try {
            $resultado = DB::transaction(function () use ($pedido, $actor, $versionEsperada, $motivo, $operationId, $hash): array {
                if ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND_RETIRO, $operationId, true)) {
                    return $this->replayOperacionPedido($existente, $hash);
                }

                $bloqueado = PedidoInventario::query()
                    ->whereKey($pedido->pedido_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((string) $bloqueado->estado !== PedidoEstado::PENDIENTE_RECEPCION) {
                    throw new OperacionRechazadaException(
                        409,
                        'Solo se puede retirar a corrección un pedido pendiente de recepción.',
                    );
                }

                $bloqueado->assertVersion($versionEsperada);
                $bloqueado->load(['lineas']);

                $this->assertSinRecepcionConfirmada($bloqueado, 'retirar');
                $this->assertSinMovimientos($bloqueado, 'retirar');

                $antes = PedidoSnapshot::capturar($bloqueado);
                $recepcionesPrevias = PedidoRecepcion::query()->where('pedido_id', $bloqueado->pedido_id)->count();

                // La nueva revisión invalida las sesiones de recepción en
                // borrador (plan sección 6): dejan de admitir conteos y no se
                // reanudan. No se borran: son historial (R15/R11).
                $invalidadas = 0;

                PedidoRecepcion::query()
                    ->where('pedido_id', $bloqueado->pedido_id)
                    ->where('estado', RecepcionEstado::BORRADOR)
                    ->orderBy('recepcion_id')
                    ->lockForUpdate()
                    ->get()
                    ->each(function (PedidoRecepcion $recepcion) use (&$invalidadas): void {
                        $recepcion->estado = RecepcionEstado::INVALIDADA;
                        $recepcion->incrementarVersion();
                        $recepcion->save();
                        $invalidadas++;
                    });

                $bloqueado->estado = PedidoEstado::BORRADOR;
                $bloqueado->incrementarVersion();
                $bloqueado->save();

                $this->invalidarAutorizacionesVigentes($bloqueado, $actor, $operationId, 'retiro_documental');

                // Nueva revisión: el esperado vuelve a ser editable hasta la
                // próxima publicación (el retiro es previo a cualquier ingreso).
                PedidoInventarioLinea::query()
                    ->where('pedido_id', $bloqueado->pedido_id)
                    ->update([
                        'esperado_congelado_at' => null,
                        'updated_at' => now(),
                    ]);

                $evento = PedidoEvento::create([
                    'pedido_id' => $bloqueado->pedido_id,
                    'pedido_linea_id' => null,
                    'event_type' => self::EVENTO_RETIRADO,
                    'actor_user_id' => $actor->id,
                    'actor_rol_snapshot' => (string) $actor->role,
                    'datos_antes' => $antes,
                    'datos_despues' => [
                        'estado' => PedidoEstado::BORRADOR,
                        'motivo' => $motivo,
                        'version' => $bloqueado->versionActual(),
                        'recepciones_previas' => $recepcionesPrevias,
                        'conteos_invalidados' => true,
                        'recepciones_invalidadas' => $invalidadas,
                    ],
                    'command_id' => $operationId,
                    'revision' => $bloqueado->versionActual(),
                ]);

                $destinatarios = $this->alertas->destinatariosPublicacion($bloqueado);
                $this->alertas->crearParaEvento($evento, AlertaTipo::PEDIDO_RETIRADO, $destinatarios);

                $payload = [
                    'pedido_id' => $bloqueado->pedido_id,
                    'uuid' => $bloqueado->uuid,
                    'numero' => $bloqueado->numero,
                    'estado' => PedidoEstado::BORRADOR,
                    'version' => $bloqueado->versionActual(),
                    'motivo' => $motivo,
                    'destinatarios_notificados' => $destinatarios->count(),
                ];

                return [
                    'operacion' => $this->registrarOperacionPedido(
                        $actor->id,
                        self::COMMAND_RETIRO,
                        $operationId,
                        $hash,
                        $bloqueado->uuid,
                        $payload,
                    ),
                    'replay' => false,
                ];
            });
        } catch (QueryException $exception) {
            if ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND_RETIRO, $operationId)) {
                return $this->replayOperacionPedido($existente, $hash);
            }

            throw $exception;
        }

        return $resultado;
    }

    /**
     * @return array{operacion: \App\Models\PedidoOperacion, replay: bool}
     */
    public function cancelar(
        PedidoInventario $pedido,
        User $actor,
        int $versionEsperada,
        string $motivo,
        string $operationId,
    ): array {
        $hash = $this->hashOperacionPedido([
            'pedido_uuid' => $pedido->uuid,
            'command' => self::COMMAND_CANCEL,
            'version' => $versionEsperada,
            'motivo' => $motivo,
        ]);

        if ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND_CANCEL, $operationId)) {
            return $this->replayOperacionPedido($existente, $hash);
        }

        try {
            $resultado = DB::transaction(function () use ($pedido, $actor, $versionEsperada, $motivo, $operationId, $hash): array {
                if ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND_CANCEL, $operationId, true)) {
                    return $this->replayOperacionPedido($existente, $hash);
                }

                $bloqueado = PedidoInventario::query()
                    ->whereKey($pedido->pedido_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! in_array((string) $bloqueado->estado, self::ESTADOS_CANCELABLES, true)) {
                    throw new OperacionRechazadaException(
                        409,
                        'No se puede cancelar un pedido que ya no está antes del ingreso.',
                    );
                }

                $bloqueado->assertVersion($versionEsperada);
                $bloqueado->load(['lineas']);

                $this->assertSinMovimientos($bloqueado, 'cancelar');

                $antes = PedidoSnapshot::capturar($bloqueado);
                $publicadoAntes = $antes['published_at'] !== null;

                $bloqueado->estado = PedidoEstado::CANCELADO;
                $bloqueado->cancelled_at = now();
                $bloqueado->cancel_motivo = $motivo;
                $bloqueado->incrementarVersion();
                $bloqueado->save();

                $this->invalidarAutorizacionesVigentes($bloqueado, $actor, $operationId, 'cancelacion_pedido');

                $evento = PedidoEvento::create([
                    'pedido_id' => $bloqueado->pedido_id,
                    'pedido_linea_id' => null,
                    'event_type' => self::EVENTO_CANCELADO,
                    'actor_user_id' => $actor->id,
                    'actor_rol_snapshot' => (string) $actor->role,
                    'datos_antes' => $antes,
                    'datos_despues' => [
                        'estado' => PedidoEstado::CANCELADO,
                        'motivo' => $motivo,
                        'version' => $bloqueado->versionActual(),
                        'publicado_antes' => $publicadoAntes,
                    ],
                    'command_id' => $operationId,
                    'revision' => $bloqueado->versionActual(),
                ]);

                $destinatarios = $publicadoAntes
                    ? $this->alertas->destinatariosPublicacion($bloqueado)
                    : collect();

                if ($destinatarios->isNotEmpty()) {
                    $this->alertas->crearParaEvento($evento, AlertaTipo::PEDIDO_CANCELADO, $destinatarios);
                }

                $payload = [
                    'pedido_id' => $bloqueado->pedido_id,
                    'uuid' => $bloqueado->uuid,
                    'numero' => $bloqueado->numero,
                    'estado' => PedidoEstado::CANCELADO,
                    'version' => $bloqueado->versionActual(),
                    'motivo' => $motivo,
                    'destinatarios_notificados' => $destinatarios->count(),
                ];

                return [
                    'operacion' => $this->registrarOperacionPedido(
                        $actor->id,
                        self::COMMAND_CANCEL,
                        $operationId,
                        $hash,
                        $bloqueado->uuid,
                        $payload,
                    ),
                    'replay' => false,
                ];
            });
        } catch (QueryException $exception) {
            if ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND_CANCEL, $operationId)) {
                return $this->replayOperacionPedido($existente, $hash);
            }

            throw $exception;
        }

        return $resultado;
    }

    private function assertSinRecepcionConfirmada(PedidoInventario $pedido, string $accion): void
    {
        $existe = PedidoRecepcion::query()
            ->where('pedido_id', $pedido->pedido_id)
            ->where('estado', RecepcionEstado::CONFIRMADA)
            ->exists();

        if ($existe) {
            throw new OperacionRechazadaException(
                409,
                sprintf('No se puede %s el pedido: ya existe una recepción confirmada.', $accion),
            );
        }
    }

    /**
     * Defensa en profundidad (U7): el retiro documental y la cancelación
     * invalidan cualquier firma de desfase vigente de las novedades del pedido.
     * En el flujo normal no existen (ambos son previos a todo ingreso), pero una
     * firma jamás sobrevive a una nueva revisión sin auditoría.
     */
    private function invalidarAutorizacionesVigentes(
        PedidoInventario $pedido,
        User $actor,
        string $operationId,
        string $motivo,
    ): void {
        $lineaIds = PedidoInventarioLinea::query()
            ->where('pedido_id', $pedido->pedido_id)
            ->pluck('linea_id');

        if ($lineaIds->isEmpty()) {
            return;
        }

        $novedades = PedidoNovedad::query()
            ->whereIn('pedido_linea_id', $lineaIds->all())
            ->get()
            ->keyBy('novedad_id');

        if ($novedades->isEmpty()) {
            return;
        }

        $vigentes = PedidoAutorizacion::query()
            ->whereIn('novedad_id', $novedades->keys()->all())
            ->where('estado', PedidoAutorizacion::ESTADO_VIGENTE)
            ->orderBy('autorizacion_id')
            ->lockForUpdate()
            ->get();

        foreach ($vigentes as $autorizacion) {
            $novedad = $novedades->get($autorizacion->novedad_id);

            $autorizacion->estado = PedidoAutorizacion::ESTADO_INVALIDADA;
            $autorizacion->save();

            PedidoEvento::create([
                'pedido_id' => $pedido->pedido_id,
                'pedido_linea_id' => $novedad?->pedido_linea_id,
                'event_type' => PedidoDesfaseService::EVENTO_INVALIDADO,
                'actor_user_id' => $actor->id,
                'actor_rol_snapshot' => (string) $actor->role,
                'datos_antes' => [
                    'autorizacion_id' => (int) $autorizacion->autorizacion_id,
                    'estado' => PedidoAutorizacion::ESTADO_VIGENTE,
                ],
                'datos_despues' => [
                    'autorizacion_id' => (int) $autorizacion->autorizacion_id,
                    'estado' => PedidoAutorizacion::ESTADO_INVALIDADA,
                    'motivo' => $motivo,
                ],
                'command_id' => $operationId,
                'revision' => $pedido->versionActual(),
            ]);
        }
    }

    /**
     * Guarda dura de ledger (R08/R15): cualquier movimiento ligado a una línea
     * del pedido bloquea retiro y cancelación. La consulta es explícita, no se
     * confía solo en el estado agregado.
     */
    private function assertSinMovimientos(PedidoInventario $pedido, string $accion): void
    {
        $lineaIds = PedidoInventarioLinea::query()
            ->where('pedido_id', $pedido->pedido_id)
            ->pluck('linea_id');

        if ($lineaIds->isNotEmpty() && TransaccionInventario::query()
            ->whereIn('pedido_linea_id', $lineaIds->all())
            ->exists()) {
            throw new OperacionRechazadaException(
                409,
                sprintf('No se puede %s el pedido: ya tiene movimientos de inventario registrados.', $accion),
            );
        }
    }
}
