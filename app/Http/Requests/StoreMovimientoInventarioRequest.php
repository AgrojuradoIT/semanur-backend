<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMovimientoInventarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isTransfer = $this->input('transaccion_tipo') === 'transferencia';

        return [
            'client_operation_id' => ['required', 'uuid'],
            'producto_id' => ['required', 'integer', 'exists:productos,producto_id'],
            'transaccion_tipo' => ['required', Rule::in(['ingreso', 'salida', 'transferencia'])],
            'transaccion_cantidad' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'transaccion_motivo' => ['nullable', 'string', 'max:255'],
            'transaccion_referencia_id' => ['nullable', 'integer'],
            'transaccion_referencia_type' => ['nullable', 'string', 'max:255'],
            'transaccion_notas' => ['nullable', 'string', 'max:5000'],
            'bodega_id' => [Rule::requiredIf(! $isTransfer), Rule::prohibitedIf($isTransfer), 'nullable', 'integer', 'exists:bodegas,bodega_id'],
            'bodega_origen_id' => [Rule::requiredIf($isTransfer), Rule::prohibitedIf(! $isTransfer), 'nullable', 'integer', 'exists:bodegas,bodega_id'],
            'bodega_destino_id' => [Rule::requiredIf($isTransfer), Rule::prohibitedIf(! $isTransfer), 'nullable', 'integer', 'different:bodega_origen_id', 'exists:bodegas,bodega_id'],
        ];
    }
}
