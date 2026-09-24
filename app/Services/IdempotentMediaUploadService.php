<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class IdempotentMediaUploadService
{
    public function __construct(
        private readonly MediaService $mediaService,
    ) {}

    /** @return array{media: Media, replayed: bool} */
    public function upload(
        User $user,
        UploadedFile $file,
        string $module,
        string $entityType,
        int $entityId,
        string $clientOperationId,
        ?string $notes = null,
    ): array {
        $operationId = strtolower(trim($clientOperationId));
        $normalizedNotes = $notes === null ? null : trim($notes);
        $contentSha256 = hash_file('sha256', $file->getPathname());

        if (! is_string($contentSha256)) {
            throw ValidationException::withMessages([
                'file' => ['No fue posible calcular la integridad del archivo.'],
            ]);
        }

        $requestHash = hash('sha256', json_encode([
            'module' => $module,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'notes' => $normalizedNotes,
            'content_sha256' => $contentSha256,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $existing = $this->findOperation($user, $operationId);
        if ($existing !== null) {
            return $this->replay($existing, $requestHash, $contentSha256);
        }

        try {
            $media = $this->mediaService->storeUploadedFile(
                file: $file,
                module: $module,
                entityType: $entityType,
                entityId: $entityId,
                userId: (int) $user->getKey(),
                notas: $normalizedNotes,
                disk: 'local',
                metadata: [
                    'client_operation_id' => $operationId,
                    'client_operation_hash' => $requestHash,
                    'content_sha256' => $contentSha256,
                ],
            );
        } catch (QueryException $exception) {
            // A concurrent request may win the unique actor+operation insert.
            $existing = $this->findOperation($user, $operationId);
            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $requestHash, $contentSha256);
        }

        return ['media' => $media, 'replayed' => false];
    }

    private function findOperation(User $user, string $operationId): ?Media
    {
        return Media::withTrashed()
            ->where('created_by', $user->getKey())
            ->where('client_operation_id', $operationId)
            ->first();
    }

    /** @return array{media: Media, replayed: bool} */
    private function replay(Media $existing, string $requestHash, string $contentSha256): array
    {
        if ($existing->trashed()) {
            throw new HttpException(409, 'La operación corresponde a una evidencia archivada.');
        }

        if (! is_string($existing->client_operation_hash)
            || ! hash_equals($existing->client_operation_hash, $requestHash)
            || ! is_string($existing->content_sha256)
            || ! hash_equals(strtolower($existing->content_sha256), strtolower($contentSha256))) {
            throw new HttpException(
                409,
                'client_operation_id ya fue utilizado con otra evidencia o contexto.',
            );
        }

        $disk = Storage::disk($existing->disk);
        if (! $disk->exists($existing->path)) {
            throw new HttpException(409, 'La evidencia registrada no está disponible para replay.');
        }

        $actualHash = hash_file('sha256', $disk->path($existing->path));
        if (! is_string($actualHash)
            || ! hash_equals(strtolower($existing->content_sha256), strtolower($actualHash))) {
            throw new HttpException(409, 'La evidencia registrada no superó la verificación de integridad.');
        }

        return ['media' => $existing, 'replayed' => true];
    }
}
