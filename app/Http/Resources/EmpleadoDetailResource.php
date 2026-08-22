<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmpleadoDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'name' => $this->name,
            'nombre_completo' => $this->nombre_completo,
            'documento' => $this->documento,
            'telefono' => $this->telefono,
            'direccion' => $this->direccion,
            'cargo' => $this->cargo,
            'licencia_conduccion' => $this->licencia_conduccion,
            'categoria_licencia' => $this->categoria_licencia,
            'vencimiento_licencia' => $this->vencimiento_licencia?->toDateString(),
            'foto_url' => $this->foto_url,
            'user_id' => $this->user_id,
            'resumen_profesional' => $this->resumen_profesional,
            'estado' => $this->estado,
            'fecha_retiro' => $this->fecha_retiro?->toDateString(),
            'motivo_retiro' => $this->motivo_retiro,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        foreach ([
            'programaciones' => 'programaciones',
            'ordenesTrabajoAsignadas' => 'ordenes_trabajo_asignadas',
            'registrosCombustible' => 'registros_combustible',
            'prestamosHerramientas' => 'prestamos_herramientas',
            'checklists' => 'checklists',
            'sesiones' => 'sesiones',
        ] as $relation => $key) {
            if ($this->resource->relationLoaded($relation)) {
                $data[$key] = $this->resource->{$relation}->toArray();
            }
        }

        foreach ([
            'ordenes_trabajo_asignadas_count',
            'prestamos_herramientas_count',
            'registros_combustible_count',
            'checklists_count',
            'sesiones_count',
            'stats',
        ] as $attribute) {
            if (array_key_exists($attribute, $this->resource->getAttributes())) {
                $data[$attribute] = $this->resource->getAttribute($attribute);
            }
        }

        if ($this->resource->relationLoaded('user')) {
            $data['user'] = $this->resource->user === null ? null : [
                'id' => $this->resource->user->id,
                'name' => $this->resource->user->name,
                'email' => $this->resource->user->email,
                'role' => $this->resource->user->role,
                'phone' => $this->resource->user->phone,
                'license_number' => $this->resource->user->license_number,
                'cargo' => $this->resource->user->cargo,
            ];
        }

        return $data;
    }
}
