<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Producto;
use Illuminate\Http\Request;

class ProductoApiController extends Controller
{
    public function index(Request $request)
    {
        $query = Producto::with('categoria');

        // Filtro por categoría
        if ($request->filled('categoria_id')) {
            $query->where('categoria_id', $request->categoria_id);
        }

        // Búsqueda por nombre, SKU o referencia
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($qb) use ($q) {
                $qb->where('producto_nombre', 'LIKE', "%{$q}%")
                    ->orWhere('producto_sku', 'LIKE', "%{$q}%")
                    ->orWhere('referencia_fabrica', 'LIKE', "%{$q}%");
            });
        }

        $query->orderBy('producto_nombre');

        // Permitir hasta 1000 productos por página para la app móvil
        $perPage = min((int) $request->get('per_page', 100), 1000);

        $paginated = $query->paginate($perPage);

        // Métricas globales (sin filtros de búsqueda)
        $metricsQuery = Producto::query();
        if ($request->filled('categoria_id')) {
            $metricsQuery->where('categoria_id', $request->categoria_id);
        }

        $totalProducts = $metricsQuery->count();
        $lowStock = (clone $metricsQuery)->whereColumn('producto_stock_actual', '<', 'producto_alerta_stock_minimo')->count();
        $totalValue = (clone $metricsQuery)->selectRaw('SUM(producto_stock_actual * producto_precio_costo) as total')->value('total') ?? 0;

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'metrics' => [
                'total_products' => $totalProducts,
                'low_stock' => $lowStock,
                'total_value' => round($totalValue, 2),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'categoria_id' => 'required|exists:categorias,categoria_id',
            'producto_sku' => 'required|string|unique:productos,producto_sku',
            'referencia_fabrica' => 'nullable|string|max:100',
            'producto_nombre' => 'required|string|max:255',
            'producto_unidad_medida' => 'required|string',
            'producto_alerta_stock_minimo' => 'nullable|numeric|min:0',
            'producto_precio_costo' => 'nullable|numeric|min:0',
            'producto_ubicacion' => 'nullable|string|max:255',
            'capacidad_maxima' => 'nullable|numeric|min:0',
        ]);

        $producto = Producto::create([
            'categoria_id' => $validated['categoria_id'],
            'producto_sku' => $validated['producto_sku'],
            'referencia_fabrica' => $validated['referencia_fabrica'] ?? null,
            'producto_nombre' => $validated['producto_nombre'],
            'producto_unidad_medida' => $validated['producto_unidad_medida'],
            'producto_stock_actual' => 0,
            'producto_alerta_stock_minimo' => $validated['producto_alerta_stock_minimo'] ?? 5,
            'producto_precio_costo' => $validated['producto_precio_costo'] ?? 0,
            'producto_ubicacion' => $validated['producto_ubicacion'] ?? null,
            'capacidad_maxima' => $validated['capacidad_maxima'] ?? null,
        ]);

        return response()->json([
            'message' => 'Producto creado con exito',
            'producto' => $producto->load('categoria'),
        ], 201);
    }

    public function show($id)
    {
        $producto = Producto::with('categoria')->find($id);

        if (!$producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        return response()->json($producto);
    }

    public function update(Request $request, $id)
    {
        $producto = Producto::find($id);

        if (!$producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        $validated = $request->validate([
            'categoria_id' => 'sometimes|exists:categorias,categoria_id',
            'producto_sku' => 'sometimes|string|unique:productos,producto_sku,' . $id . ',producto_id',
            'referencia_fabrica' => 'nullable|string|max:100',
            'producto_nombre' => 'sometimes|string|max:255',
            'producto_unidad_medida' => 'sometimes|string',
            'producto_alerta_stock_minimo' => 'nullable|numeric|min:0',
            'producto_precio_costo' => 'nullable|numeric|min:0',
            'producto_ubicacion' => 'nullable|string|max:255',
            'capacidad_maxima' => 'nullable|numeric|min:0',
        ]);

        $producto->update($validated);

        return response()->json([
            'message' => 'Producto actualizado con éxito',
            'producto' => $producto->load('categoria'),
        ]);
    }

    public function destroy($id)
    {
        $producto = Producto::find($id);

        if (!$producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        // Verificar que no tenga movimientos recientes
        $movimientos = $producto->transacciones()->count();
        if ($movimientos > 0) {
            return response()->json([
                'message' => "No se puede eliminar: el producto tiene {$movimientos} movimiento(s) asociado(s). Considere desactivarlo.",
            ], 422);
        }

        $producto->delete();

        return response()->json(['message' => 'Producto eliminado con éxito']);
    }

    public function search(Request $request)
    {
        $query = trim((string) $request->get('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $productos = Producto::with('categoria')
            ->where(function ($q) use ($query) {
                $q->where('producto_nombre', 'LIKE', "%{$query}%")
                    ->orWhere('producto_sku', 'LIKE', "%{$query}%")
                    ->orWhere('referencia_fabrica', 'LIKE', "%{$query}%");
            })
            ->get();

        return response()->json($productos);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
            'skip_duplicates' => 'boolean',
            'categoria_id' => 'nullable|exists:categorias,categoria_id',
            'unidad_medida' => 'nullable|string|max:50',
            'alerta_stock_minimo' => 'nullable|numeric|min:0',
        ]);

        $file = $request->file('file');
        $skipDuplicates = $request->boolean('skip_duplicates');
        $defaultCategoriaId = $request->input('categoria_id');
        $defaultUnidad = $request->input('unidad_medida', 'unidad');
        $defaultStockMin = (float) $request->input('alerta_stock_minimo', 5);

        $handle = fopen($file->getPathname(), 'r');
        fgetcsv($handle, 1000, ','); // encabezado opcional

        $newProducts = [];
        $duplicates = [];
        $errors = [];
        $rowNumber = 1;
        $seenSkus = [];

        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            $rowNumber++;

            if (count($data) < 2) {
                $errors[] = "Fila {$rowNumber}: datos incompletos.";
                continue;
            }

            $sku = trim((string) $data[0]);
            $nombre = trim((string) $data[1]);
            $referenciaFabrica = isset($data[2]) ? trim((string) $data[2]) : null;
            $categoriaIdFromRow = isset($data[3]) ? trim((string) $data[3]) : null;
            $unidadFromRow = isset($data[4]) ? trim((string) $data[4]) : null;
            $stockMinFromRow = isset($data[5]) ? trim((string) $data[5]) : null;
            $precioFromRow = isset($data[6]) ? trim((string) $data[6]) : null;
            $ubicacionFromRow = isset($data[7]) ? trim((string) $data[7]) : null;

            if ($sku === '' || $nombre === '') {
                $errors[] = "Fila {$rowNumber}: codigo o nombre vacios.";
                continue;
            }

            if (isset($seenSkus[$sku])) {
                $duplicates[] = ['codigo' => $sku, 'nombre' => $nombre];
                continue;
            }
            $seenSkus[$sku] = true;

            $categoriaId = null;
            if ($categoriaIdFromRow !== null && $categoriaIdFromRow !== '' && ctype_digit($categoriaIdFromRow)) {
                $categoriaId = (int) $categoriaIdFromRow;
            } elseif ($defaultCategoriaId !== null) {
                $categoriaId = (int) $defaultCategoriaId;
            }

            if (!$categoriaId || !Categoria::where('categoria_id', $categoriaId)->exists()) {
                $errors[] = "Fila {$rowNumber}: categoria_id invalida o ausente (incluye columna categoria_id o envia categoria_id en la solicitud).";
                continue;
            }

            $exists = Producto::where('producto_sku', $sku)->exists();
            if ($exists) {
                $duplicates[] = ['codigo' => $sku, 'nombre' => $nombre];
                continue;
            }

            $unidad = $unidadFromRow !== '' ? $unidadFromRow : $defaultUnidad;
            $stockMin = is_numeric($stockMinFromRow) ? max(0, (float) $stockMinFromRow) : $defaultStockMin;
            $precio = is_numeric($precioFromRow) ? max(0, (float) $precioFromRow) : 0;

            $newProducts[] = [
                'categoria_id' => $categoriaId,
                'producto_sku' => $sku,
                'referencia_fabrica' => $referenciaFabrica,
                'producto_nombre' => $nombre,
                'producto_unidad_medida' => $unidad ?: 'unidad',
                'producto_stock_actual' => 0,
                'producto_alerta_stock_minimo' => $stockMin,
                'producto_precio_costo' => $precio,
                'producto_ubicacion' => $ubicacionFromRow ?: null,
            ];
        }
        fclose($handle);

        if (!$skipDuplicates && count($duplicates) > 0) {
            return response()->json([
                'message' => 'Se encontraron duplicados.',
                'duplicates' => $duplicates,
                'new_count' => count($newProducts),
                'requires_confirmation' => true,
            ], 409);
        }

        $insertedValid = 0;
        foreach ($newProducts as $prodData) {
            try {
                Producto::create($prodData);
                $insertedValid++;
            } catch (\Exception $e) {
                $errors[] = "Error insertando {$prodData['producto_sku']}: {$e->getMessage()}";
            }
        }

        return response()->json([
            'message' => 'Importacion completada.',
            'inserted_count' => $insertedValid,
            'skipped_count' => count($duplicates),
            'errors' => $errors,
        ]);
    }
}
