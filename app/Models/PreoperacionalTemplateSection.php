<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreoperacionalTemplateSection extends Model
{
    protected $table = 'preoperacional_template_sections';

    protected $fillable = [
        'template_id',
        'nombre',
        'descripcion',
        'orden',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(PreoperacionalTemplate::class, 'template_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PreoperacionalTemplateItem::class, 'section_id')->orderBy('orden');
    }
}
