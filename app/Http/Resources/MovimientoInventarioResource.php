<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoInventarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'transaccion_id' => $this->transaccion_id,
            'reverses_transaction_id' => $this->reverses_transaction_id,
            'producto_id' => $this->producto_id,
            'bodega_id' => $this->bodega_id,
            'bodega_origen_id' => $this->bodega_origen_id,
            'bodega_destino_id' => $this->bodega_destino_id,
            'usuario_id' => $this->usuario_id,
            'transaccion_tipo' => $this->transaccion_tipo,
            'transaccion_cantidad' => $this->transaccion_cantidad,
            'transaccion_motivo' => $this->transaccion_motivo,
            'transaccion_referencia_type' => $this->transaccion_referencia_type,
            'transaccion_referencia_id' => $this->transaccion_referencia_id,
            'transaccion_notas' => $this->transaccion_notas,
            'created_at' => $this->created_at?->toISOString(),
            'producto' => $this->whenLoaded('producto', fn (): ?array => $this->producto ? [
                'producto_id' => $this->producto->producto_id,
                'categoria_id' => $this->producto->categoria_id,
                'producto_sku' => $this->producto->producto_sku,
                'referencia_fabrica' => $this->producto->referencia_fabrica,
                'producto_nombre' => $this->producto->producto_nombre,
                'producto_unidad_medida' => $this->producto->producto_unidad_medida,
                'producto_stock_actual' => $this->producto->producto_stock_actual,
                'producto_alerta_stock_minimo' => $this->producto->producto_alerta_stock_minimo,
                'capacidad_maxima' => $this->producto->capacidad_maxima,
                'producto_precio_costo' => $this->producto->producto_precio_costo,
                'producto_ubicacion' => $this->producto->producto_ubicacion,
            ] : null),
            'usuario' => $this->whenLoaded('usuario', fn (): ?array => $this->usuario ? [
                'id' => $this->usuario->id,
                'name' => $this->usuario->name,
            ] : null),
            'bodega' => $this->warehousePayload('bodega'),
            'bodega_origen' => $this->warehousePayload('bodegaOrigen'),
            'bodega_destino' => $this->warehousePayload('bodegaDestino'),
        ];
    }

    private function warehousePayload(string $relation): mixed
    {
        return $this->whenLoaded($relation, function () use ($relation): ?array {
            $bodega = $this->{$relation};

            return $bodega ? [
                'bodega_id' => $bodega->bodega_id,
                'nombre' => $bodega->nombre,
                'tipo' => $bodega->tipo,
            ] : null;
        });
    }
}
