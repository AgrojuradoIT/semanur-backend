<?php

namespace App\Http\Resources\InventoryOrders;

use App\DTO\InventoryOrders\DecimalQuantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoInventarioLineaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'linea_id' => $this->linea_id,
            'orden' => $this->orden,
            'parent_linea_id' => $this->parent_linea_id,
            'sku_raw' => $this->sku_raw,
            'sku_confirmado' => $this->sku_confirmado,
            'descripcion_raw' => $this->descripcion_raw,
            'descripcion_confirmada' => $this->descripcion_confirmada,
            'cantidad_esperada' => $this->cantidadCanonica($this->cantidad_esperada),
            'unidad' => $this->unidad,
            'producto_id' => $this->producto_id,
            'categoria_propuesta_id' => $this->categoria_propuesta_id,
            'alerta_minima_propuesta' => $this->cantidadCanonica($this->alerta_minima_propuesta),
            'precio_costo_confirmado' => $this->cantidadCanonica($this->precio_costo_confirmado),
            'estado' => $this->estado,
            'version' => $this->version,
            'esperado_congelado_at' => $this->esperado_congelado_at?->toISOString(),
        ];
    }

    /**
     * SQLite devuelve los decimales como int/float; la API siempre expone el
     * string canónico del ledger ("4.00").
     */
    private function cantidadCanonica(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            $value = number_format($value, DecimalQuantity::LEDGER_SCALE, '.', '');
        }

        return DecimalQuantity::tryNormalize($value, allowZero: true) ?? (string) $value;
    }
}
