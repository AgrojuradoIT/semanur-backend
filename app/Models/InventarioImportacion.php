<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventarioImportacion extends Model
{
    protected $table = 'inventario_importaciones';

    protected $fillable = [
        'idempotency_key',
        'request_hash',
        'file_sha256',
        'bodega_id',
        'usuario_id',
        'estado',
        'resultado',
    ];

    protected function casts(): array
    {
        return [
            'resultado' => 'array',
        ];
    }
}
