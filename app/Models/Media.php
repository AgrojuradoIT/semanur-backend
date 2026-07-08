<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

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

    /**
     * URL absoluta pública del archivo, basada en el disk configurado.
     */
    public function getUrlAttribute(): string
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($this->disk);

        return $disk->url($this->path);
    }

    /**
     * Usuario que subió la foto (opcional).
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

