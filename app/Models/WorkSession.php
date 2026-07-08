<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class WorkSession extends Model
{
    use HasFactory;

    protected $table = 'sesiones_trabajo';
    protected $primaryKey = 'sesion_id';

    protected $fillable = [
        'empleado_id',
        'orden_trabajo_id',
        'fecha_inicio',
        'fecha_fin',
        'notas',
    ];

    protected $casts = [
        'fecha_inicio' => 'datetime',
        'fecha_fin' => 'datetime',
    ];

    /**
     * Obtener el nombre de la ruta para route model binding
     */
    public function getRouteKeyName(): string
    {
        return 'sesion_id';
    }

    /**
     * Preparar el modelo para serialización JSON con timezone local
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        $carbon = Carbon::instance($date);
        // Asegurar que la fecha esté en timezone de Bogotá antes de serializar
        if ($carbon->tzName !== 'America/Bogota') {
            $carbon = $carbon->setTimezone('America/Bogota');
        }
        return $carbon->toIso8601String();
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function ordenTrabajo()
    {
        return $this->belongsTo(OrdenTrabajo::class, 'orden_trabajo_id', 'orden_trabajo_id');
    }
}
