<?php

namespace App\Services\Inventory\Orders;

use App\Domain\InventoryOrders\NovedadEstado;
use App\Domain\InventoryOrders\OperacionRechazadaException;
use App\Domain\InventoryOrders\PedidoEstado;
use App\Domain\InventoryOrders\RespuestaTipo;
use App\Models\PedidoDocumento;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoNovedad;
use App\Models\User;
use App\Services\Inventory\Orders\Concerns\GestionaOperacionesPedido;
use Illuminate\Support\Facades\DB;

/**
 * Respuestas y documentación sobre una novedad abierta (U7; plan secciones 5,
 * 11 y 12).
 *
 * Una respuesta es SOLO documentación: jamás toca stock, cantidades,
 * autorizaciones ni costos. `respuesta_contable` y
 * `entrega_complementaria_informada` avisan a la bodega habilitada (requiere
 * nuevo conteo, no ingreso automático); `nota_bodega` solo deja evento.
 */
class PedidoNovedadRespuestaService
{
    use GestionaOperacionesPedido;

    public const COMMAND_RESPOND = 'responder_novedad';

    public const EVENTO_RESPONDIDA = 'novedad_respondida';

    public function __construct(
        private readonly PedidoAlertaService $alertas,
    ) {}

    /**
     * @param  array{tipo: string, mensaje: string, documento_id?: int|null}  $datos
     * @return array{operacion: ?\App\Models\PedidoOperacion, replay: bool, payload: array<string, mixed>}
     */
    public function responder(
        PedidoInventario $pedido,
        int $novedadId,
        User $actor,
        array $datos,
        ?string $operationId = null,
    ): array {
        $tipo = (string) $datos['tipo'];
        $mensaje = trim((string) $datos['mensaje']);
        $documentoId = isset($datos['documento_id']) ? (int) $datos['documento_id'] : null;

        if (! RespuestaTipo::existe($tipo)) {
            throw new OperacionRechazadaException(422, 'El tipo de respuesta no es válido.', ['tipo' => $tipo]);
        }

        $hash = $operationId !== null
            ? $this->hashOperacionPedido([
                'pedido_uuid' => $pedido->uuid,
                'command' => self::COMMAND_RESPOND,
                'novedad_id' => $novedadId,
                'tipo' => $tipo,
                'mensaje' => $mensaje,
                'documento_id' => $documentoId,
            ])
            : '';

        if ($operationId !== null
            && ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND_RESPOND, $operationId))) {
            return $this->replay($existente, $hash);
        }

        return DB::transaction(function () use (
            $pedido,
            $novedadId,
            $actor,
            $tipo,
            $mensaje,
            $documentoId,
            $operationId,
            $hash,
        ): array {
            if ($operationId !== null
                && ($existente = $this->buscarOperacionPedido($actor->id, self::COMMAND_RESPOND, $operationId, true))) {
                return $this->replay($existente, $hash);
            }

            $orden = PedidoInventario::query()
                ->whereKey($pedido->pedido_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (PedidoEstado::esTerminal((string) $orden->estado)) {
                throw new OperacionRechazadaException(
                    409,
                    'El pedido ya está cerrado y no admite respuestas sobre novedades.',
                    ['estado' => (string) $orden->estado],
                );
            }

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
                    'La novedad ya no está abierta y no admite respuestas.',
                    ['novedad_id' => (int) $novedad->novedad_id, 'estado' => (string) $novedad->estado],
                );
            }

            $documento = null;

            if ($documentoId !== null) {
                $documento = PedidoDocumento::query()
                    ->whereKey($documentoId)
                    ->where('pedido_id', $orden->pedido_id)
                    ->lockForUpdate()
                    ->first();

                if ($documento === null) {
                    throw new OperacionRechazadaException(
                        404,
                        'El documento no existe o no pertenece a este pedido.',
                        ['documento_id' => $documentoId],
                    );
                }

                $novedad->documento_id = (int) $documento->documento_id;
                $novedad->save();
            }

            $evento = PedidoEvento::create([
                'pedido_id' => (int) $orden->pedido_id,
                'pedido_linea_id' => (int) $linea->linea_id,
                'event_type' => self::EVENTO_RESPONDIDA,
                'actor_user_id' => (int) $actor->id,
                'actor_rol_snapshot' => (string) $actor->role,
                'datos_antes' => null,
                'datos_despues' => [
                    'novedad_id' => (int) $novedad->novedad_id,
                    'tipo_respuesta' => $tipo,
                    'mensaje' => $mensaje,
                    'documento_id' => $documento?->documento_id,
                    'genera_alerta' => RespuestaTipo::generaAlerta($tipo),
                ],
                'command_id' => $operationId,
                'revision' => $orden->versionActual(),
            ]);

            $alertas = 0;

            if (RespuestaTipo::generaAlerta($tipo)) {
                $alertas = $this->alertas->crearParaEvento(
                    $evento,
                    $tipo,
                    $this->alertas->receptoresActivos(),
                );
            }

            $payload = [
                'novedad_id' => (int) $novedad->novedad_id,
                'linea_id' => (int) $linea->linea_id,
                'tipo' => $tipo,
                'mensaje' => $mensaje,
                'documento_id' => $documento?->documento_id,
                'evento_id' => (int) $evento->evento_id,
                'alertas_creadas' => $alertas,
            ];

            return [
                'operacion' => $operationId !== null
                    ? $this->registrarOperacionPedido(
                        (int) $actor->id,
                        self::COMMAND_RESPOND,
                        $operationId,
                        $hash,
                        (string) $orden->uuid,
                        $payload,
                    )
                    : null,
                'replay' => false,
                'payload' => $payload,
            ];
        }, 3);
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
}
