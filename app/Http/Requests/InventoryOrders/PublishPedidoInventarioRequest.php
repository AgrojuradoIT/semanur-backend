<?php

namespace App\Http\Requests\InventoryOrders;

use App\Services\Inventory\Orders\PedidoPublicacionService;
use Illuminate\Foundation\Http\FormRequest;

class PublishPedidoInventarioRequest extends FormRequest
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
            // Resolución humana auditada ante sospecha de compra duplicada
            // cuando no existe identidad documental fiable.
            'motivo_compra_distinta' => [
                'nullable',
                'string',
                'min:'.PedidoPublicacionService::MOTIVO_COMPRA_DISTINTA_MIN,
                'max:500',
            ],
            'estado' => ['prohibited'],
            'numero' => ['prohibited'],
        ];
    }
}
