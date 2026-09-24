<?php

namespace App\Http\Resources\InventoryOrders;

use App\DTO\InventoryOrders\DecimalQuantity;
use App\Models\PedidoRecepcionLinea;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Sesión de recepción con el panorama de conteo: todas las líneas del pedido,
 * su observación vigente (si existe) y la información mínima para decidir.
 */
class PedidoRecepcionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $recepcion = $this->resource;

        /** @var Collection<int, PedidoRecepcionLinea> $observaciones */
        $observaciones = $recepcion->relationLoaded('lineas')
            ? $recepcion->lineas->keyBy('pedido_linea_id')
            : new Collection();

        $pedido = $recepcion->relationLoaded('pedido') ? $recepcion->pedido : null;

        $lineas = $pedido !== null && $pedido->relationLoaded('lineas')
            ? $pedido->lineas->sortBy('orden')->values()
            : new Collection();

        return [
            'recepcion_id' => $this->recepcion_id,
            'pedido_uuid' => $pedido?->uuid,
            'numero' => $pedido?->numero,
            'estado' => $this->estado,
            'version' => $this->versionActual(),
            'clave_operacion' => $this->clave_operacion,
            'confirmada_at' => $this->confirmada_at?->toISOString(),
            'actor_user_id' => $this->actor_user_id,
            'receptor_empleado_id' => $this->receptor_empleado_id,
            'lineas' => $lineas->map(function ($linea) use ($observaciones, $request): array {
                $observacion = $observaciones->get($linea->linea_id);

                return [
                    'linea_id' => $linea->linea_id,
                    'orden' => $linea->orden,
                    'sku_confirmado' => $linea->sku_confirmado,
                    'descripcion_confirmada' => $linea->descripcion_confirmada,
                    'cantidad_esperada' => $this->cantidadCanonica($linea->cantidad_esperada),
                    'unidad' => $linea->unidad,
                    'producto_id' => $linea->producto_id,
                    'estado_linea' => (string) $linea->estado,
                    'requiere_ficha' => $linea->producto_id === null,
                    'observacion' => $observacion !== null
                        ? (new PedidoRecepcionLineaResource($observacion))->resolve($request)
                        : null,
                ];
            })->all(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

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
