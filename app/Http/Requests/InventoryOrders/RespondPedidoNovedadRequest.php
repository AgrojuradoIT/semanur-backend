<?php

namespace App\Http\Requests\InventoryOrders;

use App\Domain\InventoryOrders\RespuestaTipo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Respuesta documental sobre una novedad abierta (U7).
 *
 * Documentación únicamente: no acepta cantidades, importes ni datos de actor.
 * El actor sale del usuario autenticado y el adjunto debe pertenecer al pedido
 * (validado en el servicio).
 */
class RespondPedidoNovedadRequest extends FormRequest
{
    public const MENSAJE_MIN = 3;

    public const MENSAJE_MAX = 2000;

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
            'tipo' => ['required', 'string', Rule::in(RespuestaTipo::todos())],
            'mensaje' => ['required', 'string', 'min:'.self::MENSAJE_MIN, 'max:'.self::MENSAJE_MAX],
            'documento_id' => ['nullable', 'integer', 'min:1'],
            'client_operation_id' => ['nullable', 'uuid'],
            // Solo documentación: ni stock, ni cantidades, ni actor del cliente.
            'cantidad' => ['prohibited'],
            'conteo_total' => ['prohibited'],
            'conteo_aptos' => ['prohibited'],
            'cantidad_autorizada' => ['prohibited'],
            'importe' => ['prohibited'],
            'precio_unitario_confirmado' => ['prohibited'],
            'moneda' => ['prohibited'],
            'base_precio' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
            'usuario_id' => ['prohibited'],
            'authorized_by' => ['prohibited'],
            'empleado_id' => ['prohibited'],
        ];
    }
}
