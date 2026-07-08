<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermiso extends Model
{
    protected $table = 'role_permisos';

    protected $fillable = ['role', 'permisos'];

    protected function casts(): array
    {
        return [
            'permisos' => 'array',
        ];
    }
}
