<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CombustibleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'tipo_destino' => ['required', Rule::in(['vehiculo', 'empleado', 'tercero', 'equipo_menor', 'maquinaria'])],
            'tipo_combustible' => ['required', Rule::in(['gasolina', 'acpm'])],
            'cantidad_galones' => ['required', 'numeric', 'min:0.01'],
            'valor_total' => ['nullable', 'numeric', 'min:0'],
            'horometro_actual' => ['nullable', 'numeric'],
            'kilometraje_actual' => ['nullable', 'numeric'],
            'estacion_servicio' => ['nullable', 'string'],
            'notas' => ['nullable', 'string'],
            'labor' => ['nullable', 'string'],
            'placa_manual' => ['nullable', 'string'],
        ];

        if (in_array($this->input('tipo_destino'), ['vehiculo', 'maquinaria'])) {
            $rules['vehiculo_id'] = ['required', 'exists:vehiculos,vehiculo_id'];
            $rules['empleado_id'] = ['required', 'exists:empleados,id'];
        } elseif ($this->input('tipo_destino') === 'equipo_menor') {
            $rules['vehiculo_id'] = ['required', 'exists:vehiculos,vehiculo_id'];
            $rules['empleado_id'] = ['required_without:tercero_nombre', 'nullable', 'exists:empleados,id'];
            $rules['tercero_nombre'] = ['required_without:empleado_id', 'nullable', 'string'];
        } elseif ($this->input('tipo_destino') === 'empleado') {
            $rules['tercero_nombre'] = ['required', 'string'];
        } elseif ($this->input('tipo_destino') === 'tercero') {
            $rules['tercero_nombre'] = ['required', 'string'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'vehiculo_id.required' => 'El vehículo es requerido para destino vehículo.',
            'vehiculo_id.exists' => 'El vehículo seleccionado no existe.',
            'empleado_id.required' => 'El empleado (a quién se entrega) es requerido.',
            'empleado_id.exists' => 'El empleado seleccionado no existe.',
            'tercero_nombre.required' => 'El nombre del destinatario es requerido.',
            'cantidad_galones.required' => 'La cantidad en galones es requerida.',
            'cantidad_galones.min' => 'La cantidad mínima es 0.01 galones.',
        ];
    }
}
