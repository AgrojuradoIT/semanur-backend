<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bodega;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductoApiController extends Controller
{
    public function index(Request $request)
    {
        $query = Producto::with(['categoria', 'bodegas']);

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

        if ($request->boolean('low_stock')) {
            $query->where('producto_alerta_stock_minimo', '>', 0)
                ->whereColumn('producto_stock_actual', '<=', 'producto_alerta_stock_minimo');
        }

        $query->orderBy('producto_nombre');

        $perPage = min(max((int) $request->get('per_page', 100), 1), 250);

        $paginated = $query->paginate($perPage);

        // Métricas globales (sin filtros de búsqueda)
        $metricsQuery = Producto::query();
        if ($request->filled('categoria_id')) {
            $metricsQuery->where('categoria_id', $request->categoria_id);
        }

        $metrics = $metricsQuery
            ->selectRaw('COUNT(*) AS total_products')
            ->selectRaw('COALESCE(SUM(CASE WHEN producto_alerta_stock_minimo > 0 AND producto_stock_actual <= producto_alerta_stock_minimo THEN 1 ELSE 0 END), 0) AS low_stock')
            ->selectRaw('COALESCE(SUM(producto_stock_actual * producto_precio_costo), 0) AS total_value')
            ->first();

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'metrics' => [
                'total_products' => (int) ($metrics?->total_products ?? 0),
                'low_stock' => (int) ($metrics?->low_stock ?? 0),
                'total_value' => round((float) ($metrics?->total_value ?? 0), 2),
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
            'saldo_inicial' => 'nullable|numeric|min:0',
            'bodega_id' => 'nullable|exists:bodegas,bodega_id',
        ]);

        $saldoInicial = (float) ($validated['saldo_inicial'] ?? 0);
        $bodegaId = $validated['bodega_id'] ?? config('inventory.default_bodega_id');
        if ($saldoInicial > 0 && ($bodegaId === null || ! Bodega::query()->whereKey($bodegaId)->exists())) {
            throw ValidationException::withMessages([
                'bodega_id' => 'Debe seleccionar una bodega válida para registrar el saldo inicial.',
            ]);
        }

        return DB::transaction(function () use ($validated, $request, $saldoInicial, $bodegaId) {
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

            if ($saldoInicial > 0) {
                TransaccionInventario::create([
                    'producto_id' => $producto->producto_id,
                    'bodega_id' => $bodegaId,
                    'usuario_id' => $request->user()?->id,
                    'transaccion_tipo' => 'ingreso',
                    'transaccion_cantidad' => $saldoInicial,
                    'transaccion_motivo' => 'Saldo inicial',
                    'transaccion_referencia_type' => 'saldo_inicial',
                    'transaccion_referencia_id' => $producto->producto_id,
                    'transaccion_notas' => 'Saldo inicial registrado al crear el producto',
                ]);
            }

            return response()->json([
                'message' => 'Producto creado con exito',
                'producto' => $producto->fresh()->load(['categoria', 'bodegas']),
            ], 201);
        });
    }

    public function show($id)
    {
        $producto = Producto::with(['categoria', 'bodegas'])->find($id);

        if (! $producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        return response()->json($producto);
    }

    public function update(Request $request, $id)
    {
        $producto = Producto::find($id);

        if (! $producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        $validated = $request->validate([
            'categoria_id' => 'sometimes|exists:categorias,categoria_id',
            'producto_sku' => 'sometimes|string|unique:productos,producto_sku,'.$id.',producto_id',
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
            'producto' => $producto->load(['categoria', 'bodegas']),
        ]);
    }

    public function destroy($id)
    {
        $producto = Producto::find($id);

        if (! $producto) {
            return response()->json(['message' => 'Producto no encontrado'], 404);
        }

        return response()->json([
            'message' => 'La eliminación física de productos está deshabilitada para proteger el ledger de inventario.',
        ], 409);
    }

    public function search(Request $request)
    {
        $query = trim((string) $request->get('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $limit = min(max((int) $request->get('limit', 50), 1), 100);

        $productos = Producto::with(['categoria', 'bodegas'])
            ->where(function ($q) use ($query) {
                $q->where('producto_nombre', 'LIKE', "%{$query}%")
                    ->orWhere('producto_sku', 'LIKE', "%{$query}%")
                    ->orWhere('referencia_fabrica', 'LIKE', "%{$query}%");
            })
            ->orderBy('producto_nombre')
            ->limit($limit)
            ->get();

        return response()->json($productos);
    }

    public function import()
    {
        return response()->json([
            'message' => 'Este importador fue retirado. Registre la compra como pedido y confirme la recepción física en bodega.',
            'replacement' => '/api/web/inventory-orders',
        ], 410);
    }
}
