<?php

namespace App\Http\Requests;

use App\Models\Empleado;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmpleadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->exists('user_id') || $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        $routeValue = $this->route('empleado');
        $employeeId = $routeValue instanceof Empleado ? $routeValue->getKey() : $routeValue;

        return [
            'nombres' => ['sometimes', 'required', 'string', 'max:255'],
            'apellidos' => ['sometimes', 'nullable', 'string', 'max:255'],
            'documento' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('empleados', 'documento')->ignore($employeeId)],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:50'],
            'direccion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cargo' => ['sometimes', 'nullable', 'string', 'max:100', Rule::exists('cargos', 'nombre')->where('activo', true)],
            'licencia_conduccion' => ['sometimes', 'nullable', 'string', 'max:50'],
            'categoria_licencia' => ['sometimes', 'nullable', 'string', 'max:10'],
            'vencimiento_licencia' => ['sometimes', 'nullable', 'date'],
            'resumen_profesional' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'foto_url' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'estado' => ['sometimes', 'required', Rule::in(['activo', 'inactivo', 'retirado'])],
            'fecha_retiro' => ['nullable', 'date', 'required_if:estado,retirado'],
            'motivo_retiro' => ['nullable', 'string', 'max:500', 'required_if:estado,retirado'],
            'user_id' => ['sometimes', 'nullable', 'exists:users,id', Rule::unique('empleados', 'user_id')->ignore($employeeId)],
        ];
    }
}
