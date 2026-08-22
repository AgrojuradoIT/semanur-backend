<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrdenTrabajoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_operation_id' => [
                Rule::requiredIf((bool) config('inventory.require_client_operation_id', false)),
                'nullable',
                'uuid',
            ],
            'bodega_id' => ['nullable', 'integer', 'exists:bodegas,bodega_id'],
            'vehiculo_id' => ['required', 'exists:vehiculos,vehiculo_id'],
            'mecanico_asignado_id' => ['nullable', 'integer', 'exists:empleados,id'],
            'prioridad' => ['required', Rule::in(['Alta', 'Media', 'Baja'])],
            'descripcion' => ['required', 'string'],
            'repuestos' => ['nullable', 'array'],
            'repuestos.*.producto_id' => ['required_with:repuestos', 'exists:productos,producto_id'],
            'repuestos.*.cantidad' => ['required_with:repuestos', 'numeric', 'min:1'],
            'herramientas' => ['nullable', 'array'],
            'herramientas.*.producto_id' => ['required_with:herramientas', 'exists:productos,producto_id'],
            'herramientas.*.cantidad' => ['nullable', 'numeric', 'min:1'],
            'foto_evidencia' => ['nullable', 'image', 'max:5120'],
            'notas_foto_evidencia' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
