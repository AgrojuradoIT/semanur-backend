<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryOrders\StorePedidoDocumentoRequest;
use App\Http\Resources\InventoryOrders\PedidoDocumentoResource;
use App\Http\Resources\InventoryOrders\PedidoOcrIntentoResource;
use App\Models\PedidoDocumento;
use App\Models\PedidoInventario;
use App\Services\Inventory\Orders\PedidoDocumentoService;
use App\Services\Inventory\Orders\PedidoEventDispatcher;
use App\Services\Inventory\Orders\PedidoOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos privados del pedido (U3): carga multipart y descarga autorizada
 * del derivado seguro.
 *
 * La autorización vive en PedidoInventarioPolicy (uploadDocument /
 * viewDocument). La pertenencia se valida manualmente porque el grupo de rutas
 * no usa route model binding: pedido o documento ajeno responde 404 sin
 * revelar la existencia del recurso.
 */
class PedidoDocumentoController extends Controller
{
    public function __construct(
        private readonly PedidoDocumentoService $service,
        private readonly PedidoOcrService $ocr,
        private readonly PedidoEventDispatcher $dispatcher,
    ) {}

    public function store(StorePedidoDocumentoRequest $request, string $order): JsonResponse
    {
        $pedido = $this->findPedido($order);

        Gate::authorize('uploadDocument', $pedido);

        $validated = $request->validated();

        $documento = $this->service->store(
            $pedido,
            $request->file('file'),
            (string) $validated['rol'],
            isset($validated['replaces_documento_id']) ? (int) $validated['replaces_documento_id'] : null,
            isset($validated['pedido_linea_id']) ? (int) $validated['pedido_linea_id'] : null,
            $request->user(),
        );

        $documento->setRelation('pedido', $pedido);

        // U4: un pantallazo Siigo dispara la solicitud durable de OCR (intento +
        // evento outbox en una transacción) y responde 202 sin esperar OCR. La
        // factura se conserva como evidencia manual y responde 201.
        if ((string) $documento->rol === PedidoDocumentoService::ROL_PANTALLAZO_SIIGO) {
            $intento = $this->ocr->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $request->user());

            // Después del commit: si el proceso cae aquí, el scanner de la
            // outbox recupera el evento pendiente.
            $this->dispatcher->despacharPorIntento($intento);

            return response()->json([
                'success' => true,
                'data' => [
                    'documento' => (new PedidoDocumentoResource($documento))->resolve($request),
                    'intento' => (new PedidoOcrIntentoResource($intento))->resolve($request),
                ],
            ], 202);
        }

        return response()->json([
            'success' => true,
            'data' => (new PedidoDocumentoResource($documento))->resolve($request),
        ], 201);
    }

    public function content(string $order, int $document): StreamedResponse
    {
        $pedido = $this->findPedido($order);

        $documento = PedidoDocumento::query()
            ->where('documento_id', $document)
            ->where('pedido_id', $pedido->pedido_id)
            ->firstOrFail();

        Gate::authorize('viewDocument', $pedido);

        $disk = $this->service->diskName();
        $path = $this->service->previewPath($documento);

        abort_unless(Storage::disk($disk)->exists($path), 404, 'Documento no encontrado.');

        // Nombre inline saneado: solo rol y revisión, nunca storage_key/ruta.
        $filename = sprintf(
            'pedido-%s-%s-r%d.jpg',
            $pedido->uuid,
            preg_replace('/[^a-z0-9_]/i', '', (string) $documento->rol),
            (int) $documento->documento_revision,
        );

        return Storage::disk($disk)->response($path, $filename, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    private function findPedido(string $order): PedidoInventario
    {
        return PedidoInventario::query()->where('uuid', $order)->firstOrFail();
    }
}
