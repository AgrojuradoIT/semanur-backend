<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\Request;

class MediaApiController extends Controller
{
    public function __construct(
        private readonly MediaService $mediaService
    ) {
    }

    /**
     * Listar fotos por contexto (module, entity_type, entity_id).
     */
    public function index(Request $request)
    {
        $request->validate([
            'module' => 'required|string',
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
        ]);

        $media = Media::where('module', $request->module)
            ->where('entity_type', $request->entity_type)
            ->where('entity_id', $request->entity_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($media);
    }

    /**
     * Subir una nueva foto asociada a un módulo/entidad.
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|image|max:5120', // 5MB
            'module' => 'required|string|max:50',
            'entity_type' => 'required|string|max:50',
            'entity_id' => 'required|integer',
            'notas' => 'nullable|string|max:1000',
        ]);

        $file = $request->file('file');

        $media = $this->mediaService->storeUploadedFile(
            $file,
            $request->module,
            $request->entity_type,
            (int) $request->entity_id,
            $request->user()?->id,
            $request->input('notas')
        );

        return response()->json($media, 201);
    }

    /**
     * Eliminar una foto (archivo + registro).
     */
    public function destroy(Request $request, int $id)
    {
        $media = Media::findOrFail($id);

        // Opcional: validar permisos (solo el creador o roles específicos)

        $this->mediaService->deleteMedia($media);

        return response()->json(['message' => 'Media eliminada correctamente']);
    }
}

