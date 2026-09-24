<?php

namespace App\Http\Requests\InventoryOrders;

use App\DTO\InventoryOrders\DecimalQuantity;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ficha propuesta para un producto nuevo, guardada en la LINEA (U6).
 * No crea ni modifica el catálogo: eso ocurre solo en la confirmación que
 * ingresa unidades.
 */
class CatalogDraftPedidoLineaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'categoria_propuesta_id' => ['nullable', 'integer', 'exists:categorias,categoria_id'],
            'alerta_minima_propuesta' => ['nullable', 'string', $this->cantidadRule('alerta_minima_propuesta')],
            'precio_costo_confirmado' => ['nullable', 'string', $this->cantidadRule('precio_costo_confirmado')],
            'unidad' => ['nullable', 'string', 'max:40'],
            'sku_confirmado' => ['prohibited'],
            'cantidad_esperada' => ['prohibited'],
            'estado' => ['prohibited'],
            'producto_id' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
            'receptor_empleado_id' => ['prohibited'],
        ];
    }

    private function cantidadRule(string $campo): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($campo): void {
            if (DecimalQuantity::tryNormalize($value, allowZero: true) === null) {
                $fail("El campo {$campo} debe ser un decimal exacto como string, mayor o igual a cero y con la escala del ledger.");
            }
        };
    }
}
