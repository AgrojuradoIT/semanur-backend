<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrestamoHerramienta extends Model
{
    protected $table = 'prestamos_herramientas';
    protected $primaryKey = 'prestamo_id';

    protected $fillable = [
        'producto_id',
        'mecanico_id',
        'admin_id',
        'prestamo_cantidad',
        'fecha_prestamo',
        'fecha_devolucion',
        'estado',
        'notas',
    ];

    protected $casts = [
        'fecha_prestamo' => 'datetime',
        'fecha_devolucion' => 'datetime',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id', 'producto_id');
    }

    public function mecanico()
    {
        return $this->belongsTo(Empleado::class, 'mecanico_id', 'id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id', 'id');
    }
}
