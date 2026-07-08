<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSemanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehiculo_id' => ['required', 'exists:vehiculos,vehiculo_id'],
            'template_id' => ['nullable', 'exists:preoperacional_templates,id'],
            'inspector_id' => ['required', 'exists:empleados,id'],
            'semana_inicio' => ['required', 'date'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->filled('semana_inicio')) {
                $date = Carbon::parse($this->semana_inicio);
                if ($date->dayOfWeek !== Carbon::MONDAY) {
                    $validator->errors()->add(
                        'semana_inicio',
                        'La fecha debe ser un lunes. Fecha proporcionada: ' . $date->format('Y-m-d') . ' (' . $date->dayName . ')'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'vehiculo_id.required' => 'El vehículo es requerido.',
            'vehiculo_id.exists' => 'El vehículo seleccionado no existe.',
            'inspector_id.required' => 'El inspector es requerido.',
            'inspector_id.exists' => 'El inspector seleccionado no existe.',
            'template_id.exists' => 'La plantilla seleccionada no existe.',
        ];
    }
}
