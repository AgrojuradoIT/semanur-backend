<?php

namespace App\Http\Resources\InventoryOrders;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Alerta Web persistente. Payload mínimo: sin evidencias, sin OCR y sin datos
 * personales; el detalle se obtiene por la API del pedido con Policy.
 */
class PedidoAlertaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'alerta_id' => $this->alerta_id,
            'tipo' => $this->tipo,
            'pedido_id' => $this->pedido_id,
            'pedido' => $this->whenLoaded('pedido', fn (): ?array => $this->pedido === null ? null : [
                'uuid' => $this->pedido->uuid,
                'numero' => $this->pedido->numero,
                'estado' => (string) $this->pedido->estado,
            ]),
            'leida' => $this->leida_at !== null,
            'leida_at' => $this->leida_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
