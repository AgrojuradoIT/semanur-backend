<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitDailyFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'respuestas' => ['required', 'array', 'min:1'],
            'respuestas.*.item_id' => ['required', 'exists:preoperacional_template_items,id'],
            'respuestas.*.estado' => ['required', Rule::in(['B', 'M', 'C', 'NC', 'N', 'A'])],
            'respuestas.*.observacion' => ['nullable', 'string'],
            'respuestas.*.foto_url' => ['nullable', 'string'],
            'observaciones_dia' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'respuestas.required' => 'Las respuestas son requeridas.',
            'respuestas.array' => 'Las respuestas deben ser un arreglo.',
            'respuestas.*.item_id.required' => 'Cada respuesta debe incluir el ID del item.',
            'respuestas.*.item_id.exists' => 'El item seleccionado no existe.',
            'respuestas.*.estado.required' => 'Cada respuesta debe incluir un estado.',
            'respuestas.*.estado.in' => 'El estado debe ser uno de: B, M, C, NC, N, A.',
        ];
    }
}
