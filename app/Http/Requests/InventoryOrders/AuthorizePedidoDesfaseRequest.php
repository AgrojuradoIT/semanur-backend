<?php

namespace App\Http\Requests\InventoryOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Firma de desfase (U7; D1/D3).
 *
 * - D3: SOLO CANTIDADES. Cualquier campo monetario enviado por el cliente se
 *   rechaza (422) y las columnas monetarias quedan NULL en la autorización.
 * - Las cantidades (Q = A, esperado, contado, diferencia) y el contexto se
 *   calculan en servidor: el payload no puede traerlos.
 * - El actor sale del auth; `authorized_by`/`actor_user_id`/`usuario_id` son
 *   campos prohibidos.
 */
class AuthorizePedidoDesfaseRequest extends FormRequest
{
    public const MOTIVO_MIN = 10;

    public const MOTIVO_MAX = 500;

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
            'motivo' => ['required', 'string', 'min:'.self::MOTIVO_MIN, 'max:'.self::MOTIVO_MAX],
            'aceptacion_responsabilidad' => ['required', 'accepted'],
            'confirmar_inmediato' => ['sometimes', 'boolean'],
            'client_operation_id' => ['nullable', 'uuid'],
            // D3: solo cantidades; el intento monetario se rechaza explícitamente.
            'importe' => ['prohibited'],
            'precio_unitario_confirmado' => ['prohibited'],
            'moneda' => ['prohibited'],
            'base_precio' => ['prohibited'],
            // Cantidades y contexto calculados en servidor.
            'cantidad_autorizada' => ['prohibited'],
            'diferencia_cantidad' => ['prohibited'],
            'contexto_hash' => ['prohibited'],
            'order_version' => ['prohibited'],
            'line_version' => ['prohibited'],
            'count_revision' => ['prohibited'],
            // Actor desde auth, nunca del cliente.
            'actor_user_id' => ['prohibited'],
            'usuario_id' => ['prohibited'],
            'authorized_by' => ['prohibited'],
            'empleado_id' => ['prohibited'],
            'rol_snapshot' => ['prohibited'],
            'estado' => ['prohibited'],
        ];
    }
}
