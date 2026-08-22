<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OffboardEmpleadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_retiro' => ['nullable', 'date'],
            'motivo_retiro' => ['nullable', 'string', 'max:500'],
        ];
    }
}
