<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmpleadoOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'name' => $this->name,
            'nombre_completo' => $this->nombre_completo,
            'cargo' => $this->cargo,
            'estado' => $this->estado,
            'foto_url' => $this->foto_url,
        ];
    }
}
