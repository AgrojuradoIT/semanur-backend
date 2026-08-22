<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Models\PreoperacionalFormResponse;
use App\Services\Authorization\MediaContextAuthorization;
use App\Services\IdempotentMediaUploadService;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MediaApiController extends Controller
{
    public function __construct(
        private readonly MediaService $mediaService,
        private readonly MediaContextAuthorization $authorization,
        private readonly IdempotentMediaUploadService $uploads,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'module' => ['required', 'string', 'max:50'],
            'entity_type' => ['required', 'string', 'max:50'],
            'entity_id' => ['required', 'integer', 'min:1'],
        ]);

        $this->authorization->authorizeContext(
            $request->user(),
            $validated['module'],
            $validated['entity_type'],
            (int) $validated['entity_id'],
            'read',
        );

        $media = Media::query()
            ->where('module', $validated['module'])
            ->where('entity_type', $validated['entity_type'])
            ->where('entity_id', $validated['entity_id'])
            ->latest()
            ->get();

        return response()->json(
            $media->map(fn (Media $item): array => MediaResource::make($item)->resolve($request))
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'module' => ['required', 'string', 'max:50'],
            'entity_type' => ['required', 'string', 'max:50'],
            'entity_id' => ['required', 'integer', 'min:1'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'client_operation_id' => ['required', 'uuid'],
        ]);

        $entity = $this->authorization->authorizeContext(
            $request->user(),
            $validated['module'],
            $validated['entity_type'],
            (int) $validated['entity_id'],
            'write',
        );

        $result = $this->uploads->upload(
            user: $request->user(),
            file: $request->file('file'),
            module: $validated['module'],
            entityType: $validated['entity_type'],
            entityId: (int) $validated['entity_id'],
            clientOperationId: $validated['client_operation_id'],
            notes: $validated['notas'] ?? null,
        );
        $media = $result['media'];

        // Compatibility pointer for current clients. The authoritative record
        // remains the private, object-authorized Media row.
        if ($entity instanceof PreoperacionalFormResponse
            && $entity->foto_url !== $media->url) {
            $entity->update(['foto_url' => $media->url]);
        }

        return response()->json([
            'data' => MediaResource::make($media)->resolve($request),
            'client_operation_id' => strtolower($validated['client_operation_id']),
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }

    public function download(Request $request, int $id)
    {
        $media = Media::query()->findOrFail($id);
        $this->authorization->authorizeMedia($request->user(), $media, 'read');

        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->path), 404, 'El archivo no está disponible.');

        if (is_string($media->content_sha256) && $media->content_sha256 !== '') {
            $actualHash = hash_file('sha256', $disk->path($media->path));
            abort_unless(
                is_string($actualHash)
                    && hash_equals(strtolower($media->content_sha256), strtolower($actualHash)),
                409,
                'La evidencia no superó la verificación de integridad.',
            );
        }

        return $disk->response($media->path, null, [
            'Content-Type' => $media->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $media = Media::query()->findOrFail($id);
        $entity = $this->authorization->authorizeMedia($request->user(), $media, 'delete');

        DB::transaction(function () use ($media, $entity): void {
            $this->mediaService->deleteMedia($media);

            if ($entity instanceof PreoperacionalFormResponse
                && $entity->foto_url === $media->url) {
                $replacement = Media::query()
                    ->where('module', 'checklists')
                    ->where('entity_type', 'preoperacional_respuesta')
                    ->where('entity_id', $entity->getKey())
                    ->latest()
                    ->first();

                $entity->update(['foto_url' => $replacement?->url]);
            }
        });

        return response()->json(['message' => 'Archivo archivado; la evidencia física fue preservada.']);
    }
}
