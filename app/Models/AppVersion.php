<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AppVersion extends Model
{
    protected $fillable = [
        'version',
        'min_version',
        'apk_path',
        'release_notes',
        'force_update',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'force_update' => 'boolean',
        'is_active'    => 'boolean',
    ];

    // ─── Relaciones ────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Scopes ────────────────────────────────────────────────

    /**
     * La versión activa (solo puede haber una).
     */
    public static function active(): ?self
    {
        return static::where('is_active', true)->latest()->first();
    }

    // ─── Accessors ─────────────────────────────────────────────

    /**
     * URL pública de descarga del APK.
     */
    public function getDownloadUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->apk_path);
    }

    // ─── Lógica de negocio ─────────────────────────────────────

    /**
     * Activa esta versión y desactiva todas las demás.
     */
    public function activate(): void
    {
        static::where('is_active', true)->update(['is_active' => false]);
        $this->update(['is_active' => true]);
    }
}
