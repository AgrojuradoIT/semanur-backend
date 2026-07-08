<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreoperacionalTemplate extends Model
{
    protected $table = 'preoperacional_templates';

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo_vehiculo',
        'descripcion',
        'escala_predeterminada',
        'requiere_conductor',
        'requiere_documentos_vehiculo',
        'requiere_aprobacion',
        'activo',
        'version',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'requiere_conductor' => 'boolean',
        'requiere_documentos_vehiculo' => 'boolean',
        'requiere_aprobacion' => 'boolean',
        'version' => 'integer',
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(PreoperacionalTemplateSection::class, 'template_id')->orderBy('orden');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PreoperacionalTemplateItem::class, 'template_id')->orderBy('orden');
    }

    public function semanas(): HasMany
    {
        return $this->hasMany(PreoperacionalSemana::class, 'template_id');
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopePorTipo($query, string $tipo)
    {
        return $query->where('tipo_vehiculo', $tipo);
    }
}
