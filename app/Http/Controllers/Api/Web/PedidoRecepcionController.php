<?php

namespace App\Http\Controllers\Api\Web;

use App\Domain\InventoryOrders\OperacionRechazadaException;
use App\Domain\InventoryOrders\VersionDesactualizadaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryOrders\CatalogDraftPedidoLineaRequest;
use App\Http\Requests\InventoryOrders\ConfirmPedidoRecepcionRequest;
use App\Http\Requests\InventoryOrders\CountPedidoLineaRequest;
use App\Http\Resources\InventoryOrders\PedidoInventarioLineaResource;
use App\Http\Resources\InventoryOrders\PedidoRecepcionLineaResource;
use App\Http\Resources\InventoryOrders\PedidoRecepcionResource;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoRecepcion;
use App\Models\PedidoRecepcionLinea;
use App\Services\Inventory\Orders\PedidoRecepcionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Recepción de pedidos para la Web de bodega (U6; plan secciones 5, 6, 9, 11).
 *
 * Toda pertenencia pedido-recepción-línea se valida aquí (el UUID no es
 * autorización); la capacidad y el estado van por Policy, y las reglas de
 * negocio, locks e idempotencia viven en PedidoRecepcionService.
 */
class PedidoRecepcionController extends Controller
{
    /**
     * Crea la sesión de recepción borrador o devuelve la vigente.
     */
    public function store(
        Request $request,
        string $order,
        PedidoRecepcionService $receptor,
    ): JsonResponse {
        $pedido = $this->findPedido($order);

        Gate::authorize('receive', $pedido);

        try {
            $resultado = $receptor->crearOReanudar($pedido, $request->user());
        } catch (OperacionRechazadaException $exception) {
            return $this->respuestaRechazo($exception);
        }

        /** @var PedidoRecepcion $recepcion */
        $recepcion = $resultado['recepcion'];
        $recepcion->load(['pedido.lineas', 'lineas']);

        return response()->json([
            'success' => true,
            'data' => (new PedidoRecepcionResource($recepcion))->resolve($request),
            'created' => $resultado['creada'],
        ], $resultado['creada'] ? 201 : 200);
    }

    /**
     * Registra conteo y condición de una línea. No mueve stock.
     */
    public function updateLine(
        CountPedidoLineaRequest $request,
        string $order,
        int $receipt,
        int $line,
        PedidoRecepcionService $receptor,
    ): JsonResponse {
        $pedido = $this->findPedido($order);

        Gate::authorize('receive', $pedido);

        $recepcion = $this->findRecepcion($pedido, $receipt);
        $linea = $this->findLinea($pedido, $line);

        try {
            $observacion = $receptor->contarLinea($recepcion, $linea, $request->user(), $request->validated());
        } catch (OperacionRechazadaException $exception) {
            return $this->respuestaRechazo($exception);
        } catch (VersionDesactualizadaException) {
            $versionObservacion = PedidoRecepcionLinea::query()
                ->where('recepcion_id', $recepcion->recepcion_id)
                ->where('pedido_linea_id', $linea->linea_id)
                ->value('observacion_version');

            return $this->respuestaVersionDesactualizada(
                "receipts.{$receipt}.lines.{$line}",
                $versionObservacion === null ? null : (int) $versionObservacion,
            );
        }

        return response()->json([
            'success' => true,
            'data' => (new PedidoRecepcionLineaResource($observacion))->resolve($request),
        ]);
    }

    /**
     * Completa la ficha propuesta de un producto nuevo en la línea.
     */
    public function catalogDraft(
        CatalogDraftPedidoLineaRequest $request,
        string $order,
        int $line,
        PedidoRecepcionService $receptor,
    ): JsonResponse {
        $pedido = $this->findPedido($order);

        Gate::authorize('completeCatalog', $pedido);

        $linea = $this->findLinea($pedido, $line);

        try {
            $actualizada = $receptor->actualizarFicha($linea, $request->user(), $request->validated());
        } catch (OperacionRechazadaException $exception) {
            return $this->respuestaRechazo($exception);
        } catch (VersionDesactualizadaException) {
            return $this->respuestaVersionDesactualizada(
                "lines.{$line}",
                (int) PedidoInventarioLinea::query()->whereKey($linea->linea_id)->value('version'),
            );
        }

        return response()->json([
            'success' => true,
            'data' => (new PedidoInventarioLineaResource($actualizada))->resolve($request),
        ]);
    }

    /**
     * Confirma la recepción (replay seguro por operación).
     */
    public function confirm(
        ConfirmPedidoRecepcionRequest $request,
        string $order,
        int $receipt,
        PedidoRecepcionService $receptor,
    ): JsonResponse {
        $pedido = $this->findPedido($order);

        Gate::authorize('receiveCapability', $pedido);

        $recepcion = $this->findRecepcion($pedido, $receipt);
        $validated = $request->validated();

        try {
            $resultado = $receptor->confirmar(
                $pedido,
                $recepcion,
                $request->user(),
                (int) $validated['version'],
                (string) $validated['client_operation_id'],
            );
        } catch (OperacionRechazadaException $exception) {
            return $this->respuestaRechazo($exception);
        } catch (VersionDesactualizadaException) {
            return $this->respuestaVersionDesactualizada(
                "receipts.{$receipt}",
                (int) PedidoRecepcion::query()->whereKey($recepcion->recepcion_id)->value('version'),
            );
        }

        return response()->json([
            'success' => true,
            'data' => $resultado['operacion']->resultado ?? [],
            'idempotent_replay' => $resultado['replay'],
        ]);
    }

    private function findPedido(string $order): PedidoInventario
    {
        return PedidoInventario::query()->where('uuid', $order)->firstOrFail();
    }

    /**
     * La recepción debe pertenecer al pedido: un ID ajeno responde 404.
     */
    private function findRecepcion(PedidoInventario $pedido, int $receipt): PedidoRecepcion
    {
        return PedidoRecepcion::query()
            ->whereKey($receipt)
            ->where('pedido_id', $pedido->pedido_id)
            ->firstOrFail();
    }

    /**
     * La línea debe pertenecer al pedido: un ID ajeno responde 404.
     */
    private function findLinea(PedidoInventario $pedido, int $line): PedidoInventarioLinea
    {
        return PedidoInventarioLinea::query()
            ->whereKey($line)
            ->where('pedido_id', $pedido->pedido_id)
            ->firstOrFail();
    }

    private function respuestaRechazo(OperacionRechazadaException $exception): JsonResponse
    {
        return response()->json(
            array_merge(['message' => $exception->getMessage()], $exception->contexto),
            $exception->httpStatus,
        );
    }

    private function respuestaVersionDesactualizada(string $campo, ?int $versionActual): JsonResponse
    {
        return response()->json([
            'message' => 'La versión enviada no coincide con la versión vigente.',
            'campo' => $campo,
            'current_version' => $versionActual,
        ], 409);
    }
}
