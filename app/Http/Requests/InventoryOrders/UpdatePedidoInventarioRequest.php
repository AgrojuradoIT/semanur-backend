<?php

namespace App\Http\Requests\InventoryOrders;

use App\DTO\InventoryOrders\DecimalQuantity;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePedidoInventarioRequest extends FormRequest
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
            'version' => ['required', 'integer', 'min:1'],
            'proveedor_nombre_raw' => ['nullable', 'string', 'max:255'],
            // Revisión contable (U5): valores confirmados por una persona. Los
            // campos raw nunca se sobrescriben con estas correcciones.
            'proveedor_nombre_confirmado' => ['nullable', 'string', 'max:255'],
            'proveedor_clave' => ['nullable', 'string', 'max:120'],
            'referencia_factura' => ['nullable', 'string', 'max:120'],
            'bodega_id' => ['nullable', 'integer', 'exists:bodegas,bodega_id'],
            'lineas' => ['sometimes', 'array', 'min:1', 'max:200'],
            'lineas.*.orden' => ['required', 'integer', 'min:1', 'max:65535', 'distinct'],
            'lineas.*.sku_raw' => ['nullable', 'string', 'max:120'],
            'lineas.*.sku_confirmado' => ['nullable', 'string', 'max:120'],
            'lineas.*.descripcion_raw' => ['nullable', 'string', 'max:2000'],
            'lineas.*.descripcion_confirmada' => ['nullable', 'string', 'max:2000'],
            'lineas.*.cantidad_esperada' => ['required', 'string', $this->cantidadEsperadaRule()],
            'lineas.*.unidad' => ['nullable', 'string', 'max:40'],
            // Selección humana explícita: nunca hay match difuso por nombre.
            'lineas.*.producto_id' => ['nullable', 'integer', 'exists:productos,producto_id'],
            // El estado y la identidad del pedido no se editan por payload:
            // solo transiciones explicitas de U5+ pueden cambiarlos.
            'estado' => ['prohibited'],
            'uuid' => ['prohibited'],
            'created_by' => ['prohibited'],
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
