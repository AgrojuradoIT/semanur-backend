<?php

namespace App\Services;

use App\Models\Media;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MediaService
{
    /**
     * Guarda un archivo de imagen siguiendo la convención de carpetas/nombres
     * y crea el registro en la tabla media.
     */
    public function storeUploadedFile(
        UploadedFile $file,
        string $module,
        string $entityType,
        int $entityId,
        ?int $userId = null,
        ?string $notas = null,
        string $disk = 'local',
        array $metadata = [],
    ): Media {
        $now = Carbon::now();

        // Carpeta: media/{module}/YYYY/mm/dd/{entityType}_{entityId}
        $baseDir = sprintf(
            'media/%s/%s/%s/%s/%s_%d',
            $module,
            $now->format('Y'),
            $now->format('m'),
            $now->format('d'),
            $entityType,
            $entityId
        );

        $random = Str::random(6);
        $timestamp = $now->format('Ymd_His');

        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg';
        $extension = strtolower($extension);

        $fileName = sprintf(
            '%s_%s_%d_%s_%s.%s',
            $module,
            $entityType,
            $entityId,
            $timestamp,
            $random,
            $extension
        );

        // Guardar archivo en el disco configurado
        $path = $file->storeAs($baseDir, $fileName, $disk);
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The uploaded file could not be stored.');
        }

        // Tamaño y mime
        $size = $file->getSize();
        $mime = $file->getMimeType();
        $contentSha256 = $metadata['content_sha256'] ?? hash_file('sha256', $file->getPathname());

        if (! is_string($contentSha256) || preg_match('/^[a-f0-9]{64}$/i', $contentSha256) !== 1) {
            Storage::disk($disk)->delete($path);
            throw new RuntimeException('The uploaded file hash could not be calculated.');
        }

        try {
            return Media::create([
                'module' => $module,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'disk' => $disk,
                'path' => $path,
                'notas' => $notas,
                'mime_type' => $mime,
                'size' => $size,
                'created_by' => $userId ?? Auth::id(),
                'client_operation_id' => $metadata['client_operation_id'] ?? null,
                'client_operation_hash' => $metadata['client_operation_hash'] ?? null,
                'content_sha256' => strtolower($contentSha256),
            ]);
        } catch (Throwable $exception) {
            // The filesystem is not transactional. Compensate any failed DB
            // insert so a retry cannot leave an unreferenced private file.
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    /**
     * Archive the database record while retaining the physical evidence.
     * A separate, audited retention job may purge files after the legal
     * retention window; HTTP callers never perform destructive deletion.
     */
    public function deleteMedia(Media $media): void
    {
        $media->delete();
    }
}
