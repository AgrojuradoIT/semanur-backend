<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreoperacionalDailyForm extends Model
{
    protected $table = 'preoperacional_daily_forms';

    protected $fillable = [
        'semana_id',
        'dia_semana',
        'fecha',
        'completado',
        'observaciones_dia',
    ];

    protected $casts = [
        'fecha' => 'date',
        'completado' => 'boolean',
    ];

    public function semana(): BelongsTo
    {
        return $this->belongsTo(PreoperacionalSemana::class, 'semana_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(PreoperacionalFormResponse::class, 'daily_form_id');
    }

    public function scopeCompletados($query)
    {
        return $query->where('completado', true);
    }

    public function scopePendientes($query)
    {
        return $query->where('completado', false);
    }
}
