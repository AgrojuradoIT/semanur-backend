<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkFueraServicioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'El motivo es requerido.',
            'motivo.min' => 'El motivo debe tener al menos 10 caracteres.',
        ];
    }
}
