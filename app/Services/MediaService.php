<?php

namespace App\Services;

use App\Models\Media;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        string $disk = 'public'
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

        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg';
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

        // Tamaño y mime
        $size = $file->getSize();
        $mime = $file->getMimeType();

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
        ]);
    }

    /**
     * Elimina el archivo físico y el registro de media.
     */
    public function deleteMedia(Media $media): void
    {
        $disk = Storage::disk($media->disk);

        if ($disk->exists($media->path)) {
            $disk->delete($media->path);
        }

        $media->delete();
    }
}

