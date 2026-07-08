<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreoperacionalSemana extends Model
{
    protected $table = 'preoperacional_semanas';

    protected $fillable = [
        'vehiculo_id',
        'template_id',
        'inspector_id',
        'semana_inicio',
        'semana_fin',
        'semana_numero',
        'semana_anio',
        'vehiculo_marca',
        'vehiculo_modelo',
        'vehiculo_placa',
        'conductor_snapshot',
        'documentos_vehiculo_snapshot',
        'fuera_de_servicio',
        'motivo_fuera_servicio',
        'observaciones_generales',
        'estado',
        'inspector_nombre',
        'inspector_cargo',
    ];

    protected $casts = [
        'semana_inicio' => 'date',
        'semana_fin' => 'date',
        'semana_numero' => 'integer',
        'semana_anio' => 'integer',
        'fuera_de_servicio' => 'boolean',
        'conductor_snapshot' => 'array',
        'documentos_vehiculo_snapshot' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(PreoperacionalTemplate::class, 'template_id');
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'vehiculo_id', 'vehiculo_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'inspector_id');
    }

    public function dailyForms(): HasMany
    {
        return $this->hasMany(PreoperacionalDailyForm::class, 'semana_id')->orderBy('fecha');
    }

    public function scopeEnProgreso($query)
    {
        return $query->where('estado', 'en_progreso');
    }

    public function scopeCompletados($query)
    {
        return $query->where('estado', 'completado');
    }

    public function scopeFueraServicio($query)
    {
        return $query->where('fuera_de_servicio', true);
    }

    public function diasCompletados(): int
    {
        return $this->dailyForms()->where('completado', true)->count();
    }
}
