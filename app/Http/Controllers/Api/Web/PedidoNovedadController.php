<?php

namespace App\Http\Controllers\Api\Web;

use App\Domain\InventoryOrders\OperacionRechazadaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryOrders\AuthorizePedidoDesfaseRequest;
use App\Http\Requests\InventoryOrders\RespondPedidoNovedadRequest;
use App\Models\PedidoInventario;
use App\Services\Inventory\Orders\PedidoDesfaseService;
use App\Services\Inventory\Orders\PedidoNovedadRespuestaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Novedades de pedido para la Web (U7; plan secciones 5, 11 y 12).
 *
 * - `responses`: documentación de contabilidad o bodega; nunca toca stock.
 * - `authorize`: firma de desfase D1 (solo cantidades, D3) y, opcionalmente,
 *   la recepción combinada en la misma transacción atómica.
 *
 * La pertenencia {order}->{issue} se valida en el servicio (un ID ajeno
 * responde 404); la capacidad va por Policy y las reglas de negocio por los
 * servicios bajo lock.
 */
class PedidoNovedadController extends Controller
{
    public function respond(
        RespondPedidoNovedadRequest $request,
        string $order,
        int $issue,
        PedidoNovedadRespuestaService $respuestas,
    ): JsonResponse {
        $pedido = $this->findPedido($order);

        Gate::authorize('respondToDiscrepancyCapability', $pedido);

        $datos = $request->validated();

        try {
            $resultado = $respuestas->responder(
                $pedido,
                $issue,
                $request->user(),
                $datos,
                $datos['client_operation_id'] ?? null,
            );
        } catch (OperacionRechazadaException $exception) {
            return $this->respuestaRechazo($exception);
        }

        return response()->json([
            'success' => true,
            'data' => $resultado['payload'],
            'idempotent_replay' => $resultado['replay'],
        ]);
    }

    public function authorizeDesfase(
        AuthorizePedidoDesfaseRequest $request,
        string $order,
        int $issue,
        PedidoDesfaseService $desfase,
    ): JsonResponse {
        $pedido = $this->findPedido($order);

        Gate::authorize('authorizeDiscrepancyCapability', $pedido);

        $validated = $request->validated();

        try {
            $resultado = $desfase->autorizar(
                $pedido,
                $issue,
                $request->user(),
                (string) $validated['motivo'],
                (bool) ($validated['confirmar_inmediato'] ?? false),
                $validated['client_operation_id'] ?? null,
            );
        } catch (OperacionRechazadaException $exception) {
            return $this->respuestaRechazo($exception);
        }

        return response()->json([
            'success' => true,
            'data' => $resultado['payload'],
            'idempotent_replay' => $resultado['replay'],
        ]);
    }

    private function findPedido(string $order): PedidoInventario
    {
        return PedidoInventario::query()->where('uuid', $order)->firstOrFail();
    }

    private function respuestaRechazo(OperacionRechazadaException $exception): JsonResponse
    {
        return response()->json(
            array_merge(['message' => $exception->getMessage()], $exception->contexto),
            $exception->httpStatus,
        );
    }
}
