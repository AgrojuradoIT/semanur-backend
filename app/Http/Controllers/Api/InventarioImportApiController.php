<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Inventory\ComprasImportService;

class InventarioImportApiController extends Controller
{
    public function __construct(
        private readonly ComprasImportService $service
    ) {
    }

    public function importCompras(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:csv,txt,xls,xlsx',
            'dry_run' => 'nullable|boolean',
            'categoria_id' => 'nullable|exists:categorias,categoria_id',
            'unidad_medida' => 'nullable|string|max:50',
            'alerta_stock_minimo' => 'nullable|numeric|min:0',
        ]);

        $file = $validated['file'];
        $dryRun = $request->boolean('dry_run', true);

        $options = [
            'categoria_id' => $validated['categoria_id'] ?? null,
            'unidad_medida' => $validated['unidad_medida'] ?? 'unidad',
            'alerta_stock_minimo' => isset($validated['alerta_stock_minimo'])
                ? (float) $validated['alerta_stock_minimo']
                : 5.0,
        ];

        $rows = $this->service->parseFile($file);

        if (empty($rows)) {
            return response()->json([
                'message' => 'El archivo no contiene filas de datos válidas.',
            ], 422);
        }

        $preview = $this->service->buildPreview($rows, $options);

        if ($dryRun) {
            return response()->json([
                'message' => 'Previsualizacion lista.',
                'summary' => $preview['summary'],
                'existing' => array_values($preview['existing']),
                'new' => array_values($preview['new']),
                'errors' => $preview['errors'],
                'requires_confirmation' => true,
            ]);
        }

        $user = $request->user();

        $result = DB::transaction(function () use ($preview, $options, $user) {
            return $this->service->applyImport($preview, $options, $user?->id);
        });

        return response()->json($result);
    }

}

