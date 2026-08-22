<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InventoryImportConflictException;
use App\Http\Controllers\Controller;
use App\Models\Bodega;
use App\Services\Inventory\ComprasImportService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LengthException;
use RuntimeException;
use Throwable;

class InventarioImportApiController extends Controller
{
    public function __construct(
        private readonly ComprasImportService $service,
    ) {}

    public function importCompras(Request $request): JsonResponse
    {
        $maxKilobytes = max(1, (int) config('inventory.imports.max_file_kilobytes', 5120));
        $validated = $request->validate([
            'file' => "required|file|max:{$maxKilobytes}|mimes:csv,txt,xlsx",
            'dry_run' => 'nullable|boolean',
            'bodega_id' => 'nullable|integer|exists:bodegas,bodega_id',
            'idempotency_key' => ['nullable', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'categoria_id' => 'nullable|exists:categorias,categoria_id',
            'unidad_medida' => 'nullable|string|max:50',
            'alerta_stock_minimo' => 'nullable|numeric|min:0',
        ]);

        $dryRun = $request->boolean('dry_run', true);
        $bodegaId = $validated['bodega_id'] ?? config('inventory.default_bodega_id');
        if ($bodegaId === null || ! Bodega::query()->whereKey($bodegaId)->exists()) {
            throw ValidationException::withMessages([
                'bodega_id' => 'Debe seleccionar una bodega válida para la importación.',
            ]);
        }

        $options = [
            'bodega_id' => (int) $bodegaId,
            'categoria_id' => isset($validated['categoria_id']) ? (int) $validated['categoria_id'] : null,
            'unidad_medida' => $validated['unidad_medida'] ?? 'unidad',
            'alerta_stock_minimo' => isset($validated['alerta_stock_minimo'])
                ? (float) $validated['alerta_stock_minimo']
                : 5.0,
        ];

        try {
            $rows = $this->service->parseFile($validated['file']);
        } catch (LengthException|RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ($rows === []) {
            return response()->json([
                'message' => 'El archivo no contiene filas de datos válidas.',
            ], 422);
        }

        $preview = $this->service->buildPreview($rows, $options);
        if ($dryRun) {
            return response()->json([
                'message' => 'Previsualización lista.',
                'summary' => $preview['summary'],
                'existing' => array_values($preview['existing']),
                'new' => array_values($preview['new']),
                'errors' => $preview['errors'],
                'idempotency_key' => bin2hex(random_bytes(32)),
                'requires_confirmation' => $preview['errors'] === [],
            ]);
        }

        if (! isset($validated['idempotency_key'])) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Debe confirmar la importación con la clave entregada por la previsualización.',
            ]);
        }

        $idempotencyKey = $validated['idempotency_key'];
        $requestHash = $this->service->requestHash($validated['file'], $options);

        $fileSha256 = hash_file('sha256', $validated['file']->getPathname());
        if ($fileSha256 === false) {
            return response()->json(['message' => 'No se pudo validar el archivo.'], 422);
        }

        try {
            $result = $this->service->applyImport(
                $preview,
                $options,
                $request->user()?->id,
                $idempotencyKey,
                $requestHash,
                $fileSha256,
            );
        } catch (InventoryImportConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $preview['errors'],
            ], 422);
        } catch (Throwable $exception) {
            Log::error('Falló la importación transaccional de inventario.', [
                'exception' => $exception,
                'idempotency_key' => $idempotencyKey,
            ]);

            return response()->json([
                'message' => 'La importación falló y no se aplicaron cambios.',
            ], 500);
        }

        return response()->json($result);
    }
}
