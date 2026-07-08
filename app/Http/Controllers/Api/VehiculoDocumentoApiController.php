<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehiculoDocumento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class VehiculoDocumentoApiController extends Controller
{
    public function index($vehiculoId)
    {
        $documentos = VehiculoDocumento::where('vehiculo_id', $vehiculoId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($documentos);
    }

    public function store(Request $request, $vehiculoId)
    {
        $validator = Validator::make($request->all(), [
            'tipo' => 'required|in:soat,tecnomecanica',
            'fecha_inicio' => 'required|date',
            'fecha_vencimiento' => 'required|date|after:fecha_inicio',
            'compania' => 'nullable|string',
            'certificado_pdf' => 'nullable|file|mimes:pdf|max:5120', // 5MB max
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Validar que no haya documento activo del mismo tipo
        $activo = VehiculoDocumento::where('vehiculo_id', $vehiculoId)
            ->where('tipo', $request->tipo)
            ->where('estado', 'activo')
            ->where('fecha_vencimiento', '>', now())
            ->first();

        if ($activo) {
            return response()->json([
                'message' => 'Ya existe un documento ' . $request->tipo . ' activo hasta ' . $activo->fecha_vencimiento->format('d/m/Y')
            ], 400);
        }

        $data = $request->only(['tipo', 'fecha_inicio', 'fecha_vencimiento', 'compania']);
        $data['vehiculo_id'] = $vehiculoId;

        if ($request->hasFile('certificado_pdf')) {
            $file = $request->file('certificado_pdf');
            $path = $file->store('certificados', 'public');
            $data['certificado_pdf'] = $path;
        }

        $documento = VehiculoDocumento::create($data);

        return response()->json($documento, 201);
    }

    public function show($vehiculoId, $id)
    {
        $documento = VehiculoDocumento::where('vehiculo_id', $vehiculoId)->findOrFail($id);
        return response()->json($documento);
    }

    public function update(Request $request, $vehiculoId, $id)
    {
        $documento = VehiculoDocumento::where('vehiculo_id', $vehiculoId)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'fecha_inicio' => 'sometimes|date',
            'fecha_vencimiento' => 'sometimes|date|after:fecha_inicio',
            'compania' => 'nullable|string',
            'certificado_pdf' => 'nullable|file|mimes:pdf|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->only(['fecha_inicio', 'fecha_vencimiento', 'compania']);

        if ($request->hasFile('certificado_pdf')) {
            // Eliminar archivo anterior si existe
            if ($documento->certificado_pdf) {
                Storage::disk('public')->delete($documento->certificado_pdf);
            }
            $file = $request->file('certificado_pdf');
            $path = $file->store('certificados', 'public');
            $data['certificado_pdf'] = $path;
        }

        $documento->update($data);

        return response()->json($documento);
    }

    public function destroy($vehiculoId, $id)
    {
        $documento = VehiculoDocumento::where('vehiculo_id', $vehiculoId)->findOrFail($id);

        if ($documento->certificado_pdf) {
            Storage::disk('public')->delete($documento->certificado_pdf);
        }

        $documento->delete();

        return response()->json(['message' => 'Documento eliminado']);
    }
}
