<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCombustibleRequest extends FormRequest
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
        $rules = [
            'tipo_combustible' => ['sometimes', Rule::in(['gasolina', 'acpm'])],
            'cantidad_galones' => ['sometimes', 'numeric', 'min:0.01'],
            'valor_total' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'horometro_actual' => ['sometimes', 'nullable', 'numeric'],
            'kilometraje_actual' => ['sometimes', 'nullable', 'numeric'],
            'estacion_servicio' => ['sometimes', 'nullable', 'string'],
            'notas' => ['sometimes', 'nullable', 'string'],
            'labor' => ['sometimes', 'nullable', 'string'],
            'tipo_destino' => ['sometimes', Rule::in(['vehiculo', 'empleado', 'tercero', 'equipo_menor', 'maquinaria'])],
            'vehiculo_id' => ['sometimes', 'nullable', 'exists:vehiculos,vehiculo_id'],
            'empleado_id' => ['sometimes', 'nullable', 'exists:empleados,id'],
            'tercero_nombre' => ['sometimes', 'nullable', 'string'],
            'placa_manual' => ['sometimes', 'nullable', 'string'],
        ];

        if (! $this->has('tipo_destino')) {
            return $rules;
        }

        $targetType = $this->input('tipo_destino');

        if (in_array($targetType, ['vehiculo', 'maquinaria'], true)) {
            $rules['vehiculo_id'] = ['required', 'exists:vehiculos,vehiculo_id'];
            $rules['empleado_id'] = ['required', 'exists:empleados,id'];
        } elseif ($targetType === 'equipo_menor') {
            $rules['vehiculo_id'] = ['required', 'exists:vehiculos,vehiculo_id'];
            $rules['empleado_id'] = ['required_without:tercero_nombre', 'nullable', 'exists:empleados,id'];
            $rules['tercero_nombre'] = ['required_without:empleado_id', 'nullable', 'string'];
        } elseif (in_array($targetType, ['empleado', 'tercero'], true)) {
            $rules['tercero_nombre'] = ['required', 'string'];
        }

        return $rules;
    }
}
