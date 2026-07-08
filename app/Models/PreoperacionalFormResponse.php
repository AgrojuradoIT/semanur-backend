<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreoperacionalFormResponse extends Model
{
    protected $table = 'preoperacional_form_responses';

    protected $fillable = [
        'daily_form_id',
        'item_id',
        'estado',
        'observacion',
        'foto_url',
    ];

    public function dailyForm(): BelongsTo
    {
        return $this->belongsTo(PreoperacionalDailyForm::class, 'daily_form_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PreoperacionalTemplateItem::class, 'item_id');
    }

    public function scopeFallidos($query)
    {
        return $query->whereIn('estado', ['M', 'NC']);
    }

    public function scopeCriticosFallidos($query)
    {
        return $query->whereIn('estado', ['M', 'NC'])->whereHas('item', fn ($q) => $q->where('es_critico', true));
    }
}
