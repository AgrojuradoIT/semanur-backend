<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreEmpleadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->boolean('crear_usuario') || $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'nombres' => ['required', 'string', 'max:255'],
            'apellidos' => ['nullable', 'string', 'max:255'],
            'documento' => ['nullable', 'string', 'max:50', 'unique:empleados,documento'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'cargo' => ['nullable', 'string', 'max:100', Rule::exists('cargos', 'nombre')->where('activo', true)],
            'licencia_conduccion' => ['nullable', 'string', 'max:50'],
            'categoria_licencia' => ['nullable', 'string', 'max:10'],
            'vencimiento_licencia' => ['nullable', 'date'],
            'resumen_profesional' => ['nullable', 'string', 'max:5000'],
            'foto_url' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'crear_usuario' => ['sometimes', 'boolean'],
            'email' => ['required_if:crear_usuario,true', 'nullable', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => [
                'required_if:crear_usuario,true',
                'nullable',
                Password::min(12)->mixedCase()->letters()->numbers()->symbols(),
            ],
            'role' => [
                'required_if:crear_usuario,true',
                'nullable',
                Rule::in(['admin', 'jefe_taller', 'auxiliar_bodega', 'mecanico', 'operador', 'operativo', 'visualizador', 'almacenista']),
            ],
        ];
    }
}
