<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AppVersion extends Model
{
    protected $fillable = [
        'version',
        'min_version',
        'apk_path',
        'apk_sha256',
        'apk_size_bytes',
        'package_name',
        'signer_sha256',
        'release_notes',
        'force_update',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'force_update' => 'boolean',
        'is_active' => 'boolean',
        'apk_size_bytes' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $version): void {
            if ($version->isDirty('apk_path') && filled($version->apk_path)) {
                $version->forceFill($version->calculateArtifactMetadata());
            }
        });
    }

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
        return url('/api/app/download');
    }

    public function setSignerSha256Attribute(?string $value): void
    {
        $this->attributes['signer_sha256'] = $value === null
            ? null
            : strtoupper(str_replace([':', ' ', '-'], '', trim($value)));
    }

    // ─── Lógica de negocio ─────────────────────────────────────

    /**
     * Activa esta versión y desactiva todas las demás.
     */
    public function activate(): void
    {
        $metadata = $this->verifyArtifactIntegrity();
        $this->assertReleaseIdentity();

        DB::transaction(function () use ($metadata): void {
            static::query()
                ->where('is_active', true)
                ->whereKeyNot($this->getKey())
                ->update(['is_active' => false]);

            $this->forceFill($metadata + ['is_active' => true])->saveQuietly();
        });
    }

    /**
     * Verify the stored checksum/size against the file. Missing historical
     * metadata is backfilled only during an explicit activation.
     *
     * @return array{apk_sha256: string, apk_size_bytes: int}
     */
    public function verifyArtifactIntegrity(): array
    {
        $actual = $this->calculateArtifactMetadata();

        if (filled($this->apk_sha256)
            && ! hash_equals(strtolower((string) $this->apk_sha256), $actual['apk_sha256'])) {
            throw ValidationException::withMessages([
                'apk_path' => ['El SHA-256 almacenado no coincide con el APK.'],
            ]);
        }

        if ($this->apk_size_bytes !== null
            && (int) $this->apk_size_bytes !== $actual['apk_size_bytes']) {
            throw ValidationException::withMessages([
                'apk_path' => ['El tamaño almacenado no coincide con el APK.'],
            ]);
        }

        return $actual;
    }

    public function hasCompleteIntegrityMetadata(): bool
    {
        return preg_match('/^[a-f0-9]{64}$/i', (string) $this->apk_sha256) === 1
            && (int) $this->apk_size_bytes > 0
            && filled($this->package_name)
            && preg_match('/^[a-f0-9]{64}$/i', (string) $this->signer_sha256) === 1;
    }

    /** @return array{apk_sha256: string, apk_size_bytes: int} */
    private function calculateArtifactMetadata(): array
    {
        $disk = Storage::disk('public');

        if (! $disk->exists((string) $this->apk_path)) {
            throw ValidationException::withMessages([
                'apk_path' => ['El APK no existe en el almacenamiento configurado.'],
            ]);
        }

        $path = $disk->path((string) $this->apk_path);
        $hash = hash_file('sha256', $path);
        $size = filesize($path);

        if ($hash === false || $size === false || $size < 1) {
            throw ValidationException::withMessages([
                'apk_path' => ['No fue posible calcular la integridad del APK.'],
            ]);
        }

        return [
            'apk_sha256' => strtolower($hash),
            'apk_size_bytes' => (int) $size,
        ];
    }

    private function assertReleaseIdentity(): void
    {
        $errors = [];

        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/', (string) $this->package_name) !== 1) {
            $errors['package_name'] = ['El package name no tiene un formato Android válido.'];
        }

        if (preg_match('/^[a-f0-9]{64}$/i', (string) $this->signer_sha256) !== 1) {
            $errors['signer_sha256'] = ['La huella del firmante debe contener 64 caracteres hexadecimales.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
