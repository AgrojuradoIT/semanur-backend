<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreoperacionalTemplateItem extends Model
{
    protected $table = 'preoperacional_template_items';

    protected $fillable = [
        'template_id',
        'section_id',
        'codigo',
        'pregunta',
        'tipo_respuesta',
        'escala_valores',
        'es_critico',
        'requiere_observacion_si_falla',
        'orden',
    ];

    protected $casts = [
        'es_critico' => 'boolean',
        'requiere_observacion_si_falla' => 'boolean',
        'escala_valores' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(PreoperacionalTemplate::class, 'template_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(PreoperacionalTemplateSection::class, 'section_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(PreoperacionalFormResponse::class, 'item_id');
    }

    public function scopeCriticos($query)
    {
        return $query->where('es_critico', true);
    }
}
