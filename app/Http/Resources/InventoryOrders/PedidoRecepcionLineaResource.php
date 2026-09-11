<?php

namespace App\Http\Resources\InventoryOrders;

use App\DTO\InventoryOrders\DecimalQuantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoRecepcionLineaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'recepcion_linea_id' => $this->recepcion_linea_id,
            'recepcion_id' => $this->recepcion_id,
            'pedido_linea_id' => $this->pedido_linea_id,
            'conteo_total' => $this->cantidadCanonica($this->conteo_total),
            'conteo_aptos' => $this->cantidadCanonica($this->conteo_aptos),
            'estado_condicion' => $this->estado_condicion,
            'motivo' => $this->motivo,
            'esperado_snapshot' => $this->cantidadCanonica($this->esperado_snapshot),
            'observacion_version' => $this->observacion_version,
            'count_confirmed' => (bool) $this->count_confirmed,
            'reconteos' => is_array($this->snapshots['historial'] ?? null)
                ? count($this->snapshots['historial'])
                : 0,
            'registrado_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * SQLite devuelve decimales como int/float; la API expone el string
     * canónico del ledger ("4.00").
     */
    private function cantidadCanonica(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            $value = number_format($value, DecimalQuantity::LEDGER_SCALE, '.', '');
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        return DecimalQuantity::tryNormalize($value, allowZero: true) ?? (string) $value;
    }
}
