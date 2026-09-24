<?php

namespace App\Http\Requests\InventoryOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmación de recepción (U6).
 *
 * La única entrada es el UUID de operación y la versión de la recepción. No
 * existen flags received/approved/force ni listas de líneas/cantidades: la
 * elegibilidad y las cantidades se calculan en servidor bajo lock.
 */
class ConfirmPedidoRecepcionRequest extends FormRequest
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
            'estado' => ['prohibited'],
            'resulting_state' => ['prohibited'],
            'posted_lines' => ['prohibited'],
            'blocked_lines' => ['prohibited'],
            'received' => ['prohibited'],
            'approved' => ['prohibited'],
            'force' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
            'receptor_empleado_id' => ['prohibited'],
            'cantidades' => ['prohibited'],
        ];
    }
}
