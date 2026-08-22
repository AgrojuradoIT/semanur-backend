<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Media extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'media';

    protected $fillable = [
        'module',
        'entity_type',
        'entity_id',
        'disk',
        'path',
        'notas',
        'mime_type',
        'size',
        'created_by',
        'client_operation_id',
        'client_operation_hash',
        'content_sha256',
    ];

    protected $casts = [
        'size' => 'integer',
        'entity_id' => 'integer',
        'created_by' => 'integer',
    ];

    /**
     * Incluir automáticamente la URL en la representación JSON.
     */
    protected $appends = [
        'url',
    ];

    protected $hidden = [
        'disk',
        'path',
        'created_by',
        'client_operation_id',
        'client_operation_hash',
    ];

    /**
     * Authenticated API URL. The physical disk/path is never serialized.
     */
    public function getUrlAttribute(): string
    {
        return url('/api/media/'.$this->getKey().'/content');
    }

    /**
     * Usuario que subió la foto (opcional).
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
