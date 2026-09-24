<?php

namespace App\Http\Resources\InventoryOrders;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Representación pública de un documento privado del pedido.
 *
 * NUNCA expone storage_key, rutas de disco ni bucket: el único acceso es el
 * content_url relativo, autorizado por Policy en cada petición.
 */
class PedidoDocumentoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->documento_id,
            'rol' => $this->rol,
            'revision' => $this->documento_revision,
            'mime' => $this->mime_type,
            'size' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'sha256' => $this->sha256,
            'created_by' => $this->created_by,
            'replaced_document_id' => $this->replaced_document_id,
            'pedido_linea_id' => $this->pedido_linea_id,
            'created_at' => $this->created_at?->toISOString(),
            'content_url' => $this->contentUrl(),
            // BE-1: la factura PDF no genera derivado (sin renderer en el
            // proyecto). El viewer debe leer este flag, no inferir por rol.
            'preview_disponible' => $this->previewDisponible(),
            'latest_ocr_attempt' => $this->whenLoaded(
                'latestOcrIntento',
                fn (): ?array => $this->latestOcrIntento === null
                    ? null
                    : (new PedidoOcrIntentoResource($this->latestOcrIntento))->resolve($request),
            ),
        ];
    }

    private function previewDisponible(): bool
    {
        try {
            $disk = (string) config('inventory_orders.documents.disk', 'local');

            if ($disk === '' || $disk === 'public') {
                $disk = 'local';
            }

            return Storage::disk($disk)->exists(
                dirname((string) $this->storage_key).'/preview.jpg',
            );
        } catch (\Throwable) {
            return $this->mime_type !== 'application/pdf';
        }
    }

    private function contentUrl(): ?string
    {
        $pedido = $this->relationLoaded('pedido') ? $this->pedido : null;

        if ($pedido === null || $pedido->uuid === null) {
            return null;
        }

        return sprintf(
            '/api/web/inventory-orders/%s/documents/%d/content',
            $pedido->uuid,
            $this->documento_id,
        );
    }
}
