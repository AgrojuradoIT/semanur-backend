<?php

namespace App\Http\Requests\InventoryOrders;

use Illuminate\Foundation\Http\FormRequest;

class ReturnPedidoInventarioRequest extends FormRequest
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
            'motivo' => ['required', 'string', 'min:5', 'max:255'],
            'estado' => ['prohibited'],
        ];
    }
}
