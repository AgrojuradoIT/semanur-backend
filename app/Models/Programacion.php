<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Programacion extends Model
{
    use HasFactory;

    protected $table = 'programacion';

    protected $fillable = [
        'fecha',
        'empleado_id',
        'vehiculo_id',
        'labor',
        'ubicacion',
        'estado',
        'orden_trabajo_id',
        'es_novedad',
    ];

    protected $casts = [
        'fecha' => 'date',
        'es_novedad' => 'boolean',
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class, 'vehiculo_id', 'vehiculo_id');
    }

    public function ordenTrabajo()
    {
        return $this->belongsTo(OrdenTrabajo::class, 'orden_trabajo_id', 'orden_trabajo_id');
    }
}
