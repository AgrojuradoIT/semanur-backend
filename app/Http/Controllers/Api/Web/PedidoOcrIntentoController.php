<?php

namespace App\Http\Controllers\Api\Web;

use App\Domain\InventoryOrders\OcrIntentoActivoException;
use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryOrders\PedidoOcrIntentoResource;
use App\Models\PedidoDocumento;
use App\Models\PedidoInventario;
use App\Models\PedidoOcrIntento;
use App\Services\Inventory\Orders\PedidoDocumentoService;
use App\Services\Inventory\Orders\PedidoEventDispatcher;
use App\Services\Inventory\Orders\PedidoOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Intento OCR del pedido (U4): consulta de estado/candidatos y reproceso
 * explícito del pantallazo Siigo.
 *
 * El grupo de rutas no usa route model binding: la pertenencia pedido-documento
 * se valida manualmente y un recurso ajeno responde 404 sin revelar existencia.
 * La autorización vive en PedidoInventarioPolicy (viewOcrAttempt /
 * reprocessOcr), deny by default.
 */
class PedidoOcrIntentoController extends Controller
{
    public function __construct(
        private readonly PedidoOcrService $ocr,
        private readonly PedidoEventDispatcher $dispatcher,
    ) {}

    public function show(Request $request, string $order, int $attempt): JsonResponse
    {
        $pedido = $this->findPedido($order);

        Gate::authorize('viewOcrAttempt', $pedido);

        $intento = PedidoOcrIntento::query()
            ->where('intento_id', $attempt)
            ->whereHas('documento', fn ($query) => $query->where('pedido_id', $pedido->pedido_id))
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => (new PedidoOcrIntentoResource($intento))->resolve($request),
        ]);
    }

    public function reprocess(Request $request, string $order, int $document): JsonResponse
    {
        $pedido = $this->findPedido($order);

        Gate::authorize('reprocessOcr', $pedido);

        $documento = PedidoDocumento::query()
            ->where('documento_id', $document)
            ->where('pedido_id', $pedido->pedido_id)
            ->firstOrFail();

        if ((string) $documento->rol !== PedidoDocumentoService::ROL_PANTALLAZO_SIIGO) {
            throw ValidationException::withMessages([
                'document' => 'Solo los pantallazos de Siigo admiten reproceso OCR.',
            ]);
        }

        try {
            $intento = $this->ocr->solicitar(
                $documento,
                PedidoOcrService::ORIGEN_REPROCESO,
                $request->user(),
                true,
            );
        } catch (OcrIntentoActivoException $exception) {
            $activo = $exception->intentoId() !== null
                ? PedidoOcrIntento::query()->find($exception->intentoId())
                : null;

            return response()->json([
                'message' => $exception->getMessage(),
                'data' => [
                    'intento' => $activo !== null
                        ? (new PedidoOcrIntentoResource($activo))->resolve($request)
                        : null,
                ],
            ], 409);
        }

        // Después del commit de solicitar(): si el proceso cae aquí, el scanner
        // de la outbox recupera el evento pendiente.
        $this->dispatcher->despacharPorIntento($intento);

        return response()->json([
            'success' => true,
            'data' => (new PedidoOcrIntentoResource($intento))->resolve($request),
        ], 202);
    }

    private function findPedido(string $order): PedidoInventario
    {
        return PedidoInventario::query()->where('uuid', $order)->firstOrFail();
    }
}
