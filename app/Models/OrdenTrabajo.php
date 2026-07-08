<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class OrdenTrabajo extends Model
{
    protected $primaryKey = 'orden_trabajo_id';

    protected $fillable = [
        'vehiculo_id',
        'mecanico_asignado_id',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'prioridad',
        'descripcion',
        'foto_evidencia',
        'notas_auditoria',
    ];

    protected $casts = [
        'fecha_inicio' => 'datetime:Y-m-d H:i:s',
        'fecha_fin' => 'datetime:Y-m-d H:i:s',
        'mecanico_asignado_id' => 'integer',
        'vehiculo_id' => 'integer',
    ];

    /**
     * Obtener el nombre de la ruta para route model binding
     * Esto soluciona el error de Backpack con la primary key personalizada
     */
    public function getRouteKeyName(): string
    {
        return 'orden_trabajo_id';
    }

    /**
     * Preparar el modelo para serialización JSON con timezone local
     * Se sobrescribe para asegurar que las fechas se serialicen en Bogota
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

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'vehiculo_id', 'vehiculo_id');
    }

    public function movimientos_inventario()
    {
        return $this->hasMany(TransaccionInventario::class, 'transaccion_referencia_id', 'orden_trabajo_id')
                    ->where('transaccion_referencia_type', 'OrdenTrabajo');
    }

    public function mecanico(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'mecanico_asignado_id', 'id');
    }

    public function sesiones()
    {
        return $this->hasMany(WorkSession::class, 'orden_trabajo_id', 'orden_trabajo_id');
    }

    public function evidencias()
    {
        return $this->hasMany(Media::class, 'entity_id', 'orden_trabajo_id')
                    ->where('entity_type', 'orden_trabajo')
                    ->where('module', 'taller');
    }
}
