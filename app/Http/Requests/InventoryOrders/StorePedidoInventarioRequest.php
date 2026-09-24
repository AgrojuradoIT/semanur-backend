<?php

namespace App\Http\Requests\InventoryOrders;

use App\DTO\InventoryOrders\DecimalQuantity;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StorePedidoInventarioRequest extends FormRequest
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
            'client_operation_id' => ['required', 'uuid'],
            'proveedor_nombre_raw' => ['nullable', 'string', 'max:255'],
            'referencia_factura' => ['nullable', 'string', 'max:120'],
            'bodega_id' => ['required', 'integer', 'exists:bodegas,bodega_id'],
            'lineas' => ['required', 'array', 'min:1', 'max:200'],
            'lineas.*.orden' => ['required', 'integer', 'min:1', 'max:65535', 'distinct'],
            'lineas.*.sku_raw' => ['nullable', 'string', 'max:120'],
            'lineas.*.descripcion_raw' => ['nullable', 'string', 'max:2000'],
            'lineas.*.cantidad_esperada' => ['required', 'string', $this->cantidadEsperadaRule()],
        ];
    }

    /**
     * La cantidad viaja como string decimal y se valida con el DTO de U1 para
     * rechazar floats, notación científica, negativos, cero y escala mayor al
     * ledger.
     */
    protected function cantidadEsperadaRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (DecimalQuantity::tryNormalize($value, false) === null) {
                $fail('La cantidad esperada debe ser un decimal exacto como string, mayor que cero y con la escala del ledger.');
            }
        };
    }
}
