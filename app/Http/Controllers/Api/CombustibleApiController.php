<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CombustibleRequest;
use App\Http\Requests\UpdateCombustibleRequest;
use App\Models\BodegaProducto;
use App\Models\Producto;
use App\Models\RegistroCombustible;
use App\Models\TransaccionInventario;
use App\Services\CombustibleService;
use App\Services\Inventory\InventoryWarehouseResolver;
use App\Support\ClientOperationHasher;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CombustibleApiController extends Controller
{
    public function __construct(
        private readonly CombustibleService $combustibleService,
        private readonly InventoryWarehouseResolver $warehouseResolver,
    ) {}

    public function index(Request $request)
    {
        $query = RegistroCombustible::with(['vehiculo', 'empleado', 'usuario'])
            ->orderBy('fecha', 'desc');

        if ($request->filled('vehiculo_id')) {
            $query->where('vehiculo_id', $request->vehiculo_id);
        }
        if ($request->filled('tipo_combustible')) {
            $query->where('tipo_combustible', $request->tipo_combustible);
        }
        if ($request->filled('tipo_destino')) {
            $query->where('tipo_destino', $request->tipo_destino);
        }
        if ($request->filled('fecha_desde')) {
            $query->where('fecha', '>=', Carbon::parse($request->fecha_desde)->startOfDay());
        }
        if ($request->filled('fecha_hasta')) {
            $query->where('fecha', '<=', Carbon::parse($request->fecha_hasta)->endOfDay());
        }
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->whereHas('vehiculo', function ($vq) use ($search) {
                    $vq->where('placa', 'like', "%{$search}%")
                        ->orWhere('marca', 'like', "%{$search}%");
                })->orWhereHas('empleado', function ($eq) use ($search) {
                    $eq->where('name', 'like', "%{$search}%")
                        ->orWhere('nombre', 'like', "%{$search}%");
                })->orWhere('tercero_nombre', 'like', "%{$search}%")
                    ->orWhere('labor', 'like', "%{$search}%")
                    ->orWhere('notas', 'like', "%{$search}%")
                    ->orWhere('estacion_servicio', 'like', "%{$search}%")
                    ->orWhere('placa_manual', 'like', "%{$search}%");
            });
        }
        $perPageParam = $request->get('per_page', 25);
        if ($perPageParam === 'all' || (int) $perPageParam >= 9999 || $request->boolean('all')) {
            $items = $query->get();

            return response()->json([
                'data' => $items,
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $items->count(),
                    'total' => $items->count(),
                ],
            ]);
        }

        $perPage = min(max((int) $perPageParam, 1), 500);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function summary(Request $request)
    {
        $query = RegistroCombustible::query();

        if ($request->filled('fecha_desde')) {
            $query->where('fecha', '>=', Carbon::parse($request->fecha_desde)->startOfDay());
        }
        if ($request->filled('fecha_hasta')) {
            $query->where('fecha', '<=', Carbon::parse($request->fecha_hasta)->endOfDay());
        }

        $gasolina = (clone $query)->where('tipo_combustible', 'gasolina');
        $acpm = (clone $query)->where('tipo_combustible', 'acpm');
        $totalQuery = (clone $query);

        return response()->json([
            'total_registros' => $totalQuery->count(),
            'gasolina_galones' => round($gasolina->sum('cantidad_galones'), 2),
            'gasolina_valor' => round($totalQuery->where('tipo_combustible', 'gasolina')->sum('valor_total'), 2),
            'acpm_galones' => round($acpm->sum('cantidad_galones'), 2),
            'acpm_valor' => round($totalQuery->where('tipo_combustible', 'acpm')->sum('valor_total'), 2),
            'valor_total' => round($totalQuery->sum('valor_total'), 2),
        ]);
    }

    public function store(CombustibleRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $actorId = (int) $request->user()->id;
        $operationId = $validated['client_operation_id'] ?? null;

        try {
            $warehouse = $this->warehouseResolver->resolve(
                isset($validated['bodega_id']) ? (int) $validated['bodega_id'] : null,
            );
            $operationHash = $operationId === null
                ? null
                : ClientOperationHasher::hash($this->fuelOperationPayload($validated, $warehouse->bodega_id));

            if ($operationId !== null) {
                $existing = $this->findFuelOperation($actorId, $operationId);
                if ($existing !== null) {
                    return $this->fuelReplayResponse($existing, $operationHash);
                }
            } else {
                Log::notice('Fuel write received without client_operation_id during compatibility rollout.', [
                    'actor_id' => $actorId,
                ]);
            }

            $registro = DB::transaction(function () use (
                $validated,
                $actorId,
                $operationId,
                $operationHash,
                $warehouse,
            ): RegistroCombustible {
                if ($operationId !== null) {
                    $existing = $this->findFuelOperation($actorId, $operationId, true);
                    if ($existing !== null) {
                        if (! hash_equals((string) $existing->client_operation_hash, (string) $operationHash)) {
                            throw ValidationException::withMessages([
                                'client_operation_id' => 'La clave ya fue usada con un abastecimiento diferente.',
                            ]);
                        }

                        return $existing;
                    }
                }

                $empleadoId = in_array($validated['tipo_destino'], ['vehiculo', 'maquinaria', 'equipo_menor'], true)
                    ? ($validated['empleado_id'] ?? null)
                    : null;

                $resolvedProduct = $this->combustibleService->resolveCombustibleProducto($validated['tipo_combustible']);

                if (! $resolvedProduct) {
                    throw ValidationException::withMessages([
                        'tipo_combustible' => "No se encontró el producto de combustible ({$validated['tipo_combustible']}).",
                    ]);
                }

                $producto = Producto::query()
                    ->whereKey($resolvedProduct->producto_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($producto->producto_stock_actual < $validated['cantidad_galones']) {
                    throw ValidationException::withMessages([
                        'cantidad_galones' => "Stock insuficiente de {$validated['tipo_combustible']}. Stock actual: {$producto->producto_stock_actual}",
                    ]);
                }

                [$refType, $refId, $notas] = $this->combustibleService->buildReferencia(
                    $validated['tipo_destino'],
                    $validated['vehiculo_id'] ?? null,
                    $validated['tercero_nombre'] ?? null,
                    $validated['labor'] ?? null,
                );

                $bodegaStock = BodegaProducto::query()
                    ->where('bodega_id', $warehouse->bodega_id)
                    ->where('producto_id', $producto->producto_id)
                    ->lockForUpdate()
                    ->first();

                if (! $bodegaStock) {
                    throw ValidationException::withMessages([
                        'bodega_id' => "El producto {$validated['tipo_combustible']} no tiene saldo configurado en {$warehouse->nombre}.",
                    ]);
                }

                if ((float) $bodegaStock->cantidad < (float) $validated['cantidad_galones']) {
                    throw ValidationException::withMessages([
                        'cantidad_galones' => "Stock insuficiente de {$validated['tipo_combustible']} en {$warehouse->nombre}. Stock actual: {$bodegaStock->cantidad}",
                    ]);
                }

                $transaccion = TransaccionInventario::create([
                    'producto_id' => $producto->producto_id,
                    'bodega_id' => $warehouse->bodega_id,
                    'usuario_id' => $actorId,
                    'transaccion_tipo' => 'salida',
                    'transaccion_cantidad' => $validated['cantidad_galones'],
                    'transaccion_motivo' => 'Consumo de Combustible (Interno)',
                    'transaccion_referencia_id' => $refId,
                    'transaccion_referencia_type' => $refType,
                    'transaccion_notas' => $notas,
                ]);

                return RegistroCombustible::create([
                    'vehiculo_id' => $validated['vehiculo_id'] ?? null,
                    'empleado_id' => $empleadoId,
                    'tercero_nombre' => $validated['tercero_nombre'] ?? null,
                    'tipo_destino' => $validated['tipo_destino'],
                    'tipo_combustible' => $validated['tipo_combustible'],
                    'usuario_id' => $actorId,
                    'client_operation_id' => $operationId,
                    'client_operation_hash' => $operationHash,
                    'fecha' => Carbon::now(),
                    'cantidad_galones' => $validated['cantidad_galones'],
                    'valor_total' => $validated['valor_total'] ?? 0,
                    'horometro_actual' => $validated['horometro_actual'] ?? null,
                    'kilometraje_actual' => $validated['kilometraje_actual'] ?? null,
                    'estacion_servicio' => $validated['estacion_servicio'] ?? null,
                    'placa_manual' => $validated['placa_manual'] ?? null,
                    'notas' => $validated['notas'] ?? null,
                    'labor' => $validated['labor'] ?? null,
                    'transaccion_id' => $transaccion->transaccion_id,
                ]);
            });

            $idempotentReplay = $operationId !== null && ! $registro->wasRecentlyCreated;

            return response()->json([
                'message' => $idempotentReplay
                    ? 'El abastecimiento ya había sido registrado.'
                    : 'Registro de combustible creado con éxito',
                'registro' => $registro->load(['vehiculo', 'empleado']),
                'idempotent_replay' => $idempotentReplay,
                'idempotency_protected' => $operationId !== null,
            ], $idempotentReplay ? 200 : 201);
        } catch (ValidationException $exception) {
            if ($operationId !== null && isset($operationHash)) {
                $existing = $this->findFuelOperation($actorId, $operationId);
                if ($existing !== null) {
                    return $this->fuelReplayResponse($existing, $operationHash);
                }
            }

            throw $exception;
        } catch (QueryException $exception) {
            if ($operationId !== null && isset($operationHash)) {
                $existing = $this->findFuelOperation($actorId, $operationId);
                if ($existing !== null) {
                    return $this->fuelReplayResponse($existing, $operationHash);
                }
            }

            return $this->fuelServerError($exception, $actorId, $operationId);
        } catch (Throwable $exception) {
            return $this->fuelServerError($exception, $actorId, $operationId);
        }
    }

    public function update(UpdateCombustibleRequest $request, $id)
    {
        $registro = RegistroCombustible::find($id);

        if (! $registro) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        $result = $this->combustibleService->updateRegistro(
            $registro,
            $request->validated(),
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Registro actualizado con éxito',
            'ajuste_inventario' => $result['inventory_adjusted'],
            'registro' => $result['registro'],
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $registro = RegistroCombustible::find($id);

        if (! $registro) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        $this->combustibleService->destroyRegistro($registro, $request->user()?->id);

        return response()->json([
            'message' => 'Registro eliminado y stock revertido',
            'inventario_revertido' => true,
        ]);
    }

    public function reportes(Request $request)
    {
        $fechaDesde = $request->filled('fecha_desde') ? Carbon::parse($request->fecha_desde)->startOfDay() : null;
        $fechaHasta = $request->filled('fecha_hasta') ? Carbon::parse($request->fecha_hasta)->endOfDay() : null;

        // Base query para filtros
        $baseQuery = RegistroCombustible::query();
        if ($fechaDesde) {
            $baseQuery->where('fecha', '>=', $fechaDesde);
        }
        if ($fechaHasta) {
            $baseQuery->where('fecha', '<=', $fechaHasta);
        }

        // 1. Data combinada (General)
        $general = $this->buildSegmentedData(clone $baseQuery);

        // 2. Data segmentada por tipo de combustible
        $gasolinaQuery = (clone $baseQuery)->where('tipo_combustible', 'gasolina');
        $gasolina = $this->buildSegmentedData($gasolinaQuery);

        $acpmQuery = (clone $baseQuery)->where('tipo_combustible', 'acpm');
        $acpm = $this->buildSegmentedData($acpmQuery);

        // 3. Consumo Diario (para gráficos de evolución diario combinados y por separado)
        $consumoDiario = (clone $baseQuery)
            ->select(
                DB::raw('DATE(fecha) as fecha_dia'),
                DB::raw('SUM(CASE WHEN tipo_combustible = "gasolina" THEN cantidad_galones ELSE 0 END) as gasolina'),
                DB::raw('SUM(CASE WHEN tipo_combustible = "acpm" THEN cantidad_galones ELSE 0 END) as acpm'),
                DB::raw('SUM(cantidad_galones) as total')
            )
            ->groupBy('fecha_dia')
            ->orderBy('fecha_dia')
            ->get()
            ->map(function ($item) {
                return [
                    'fecha' => $item->fecha_dia,
                    'gasolina' => round($item->gasolina, 2),
                    'acpm' => round($item->acpm, 2),
                    'total' => round($item->total, 2),
                ];
            });

        return response()->json([
            'kpis' => $general['kpis'],
            'consumo_por_tipo_destino' => $general['consumo_por_tipo_destino'],
            'top_consumidores' => $general['top_consumidores'],
            'consumo_por_dia_semana' => $general['consumo_por_dia_semana'],

            'kpis_gasolina' => $gasolina['kpis'],
            'consumo_por_tipo_destino_gasolina' => $gasolina['consumo_por_tipo_destino'],
            'top_consumidores_gasolina' => $gasolina['top_consumidores'],
            'consumo_por_dia_semana_gasolina' => $gasolina['consumo_por_dia_semana'],

            'kpis_acpm' => $acpm['kpis'],
            'consumo_por_tipo_destino_acpm' => $acpm['consumo_por_tipo_destino'],
            'top_consumidores_acpm' => $acpm['top_consumidores'],
            'consumo_por_dia_semana_acpm' => $acpm['consumo_por_dia_semana'],

            'consumo_diario' => $consumoDiario,
        ]);
    }

    private function buildSegmentedData($query)
    {
        $totalRegistros = (clone $query)->count();
        $totalGalones = round((clone $query)->sum('cantidad_galones'), 2);

        $vehiculosUnicos = (clone $query)->whereNotNull('vehiculo_id')->distinct('vehiculo_id')->count('vehiculo_id');

        $diasDistintos = (clone $query)->select(DB::raw('DATE(fecha) as fecha_dia'))->distinct()->get()->count();
        $promedioDiario = $diasDistintos > 0 ? round($totalGalones / $diasDistintos, 2) : 0;

        $topDia = (clone $query)
            ->select(DB::raw('DATE(fecha) as fecha_dia'), DB::raw('SUM(cantidad_galones) as total_galones'))
            ->groupBy('fecha_dia')
            ->orderByDesc('total_galones')
            ->first();

        $kpis = [
            'total_galones' => $totalGalones,
            'total_registros' => $totalRegistros,
            'promedio_galones_diario' => $promedioDiario,
            'vehiculos_unicos' => $vehiculosUnicos,
            'top_dia' => $topDia ? [
                'fecha' => $topDia->fecha_dia,
                'galones' => round($topDia->total_galones, 2),
            ] : null,
        ];

        $destino = (clone $query)
            ->select(
                'tipo_destino',
                DB::raw('SUM(cantidad_galones) as galones'),
                DB::raw('COUNT(*) as registros')
            )
            ->groupBy('tipo_destino')
            ->get()
            ->map(function ($item) {
                return [
                    'tipo_destino' => $item->tipo_destino,
                    'galones' => round($item->galones, 2),
                    'registros' => $item->registros,
                ];
            });

        $topConsumersRaw = (clone $query)
            ->select(
                'vehiculo_id',
                'placa_manual',
                'tercero_nombre',
                'tipo_destino',
                DB::raw('SUM(cantidad_galones) as galones'),
                DB::raw('COUNT(*) as registros')
            )
            ->with(['vehiculo'])
            ->groupBy('vehiculo_id', 'placa_manual', 'tercero_nombre', 'tipo_destino')
            ->orderByDesc('galones')
            ->limit(10)
            ->get();

        $topConsumers = $topConsumersRaw->map(function ($item) {
            $nombre = '';
            if ($item->vehiculo) {
                $nombre = ($item->vehiculo->placa ?? '').' ('.($item->vehiculo->nombre ?? $item->vehiculo->marca ?? '').')';
            } elseif ($item->tipo_destino === 'empleado' && $item->tercero_nombre) {
                $nombre = $item->tercero_nombre.($item->placa_manual ? " - {$item->placa_manual}" : '');
            } elseif ($item->tercero_nombre) {
                $nombre = $item->tercero_nombre;
            } elseif ($item->placa_manual) {
                $nombre = $item->placa_manual;
            } else {
                $nombre = 'Destino Desconocido';
            }

            return [
                'destino' => $nombre,
                'galones' => round($item->galones, 2),
                'registros' => $item->registros,
            ];
        });

        $consumoSemanal = (clone $query)
            ->select(
                DB::raw('DAYOFWEEK(fecha) as dia_num'),
                DB::raw('SUM(cantidad_galones) as galones')
            )
            ->groupBy('dia_num')
            ->orderBy('dia_num')
            ->get();

        $diasLabels = [
            1 => 'Domingo',
            2 => 'Lunes',
            3 => 'Martes',
            4 => 'Miércoles',
            5 => 'Jueves',
            6 => 'Viernes',
            7 => 'Sábado',
        ];

        $consumoPorDiaSemana = collect($diasLabels)->map(function ($label, $num) use ($consumoSemanal) {
            $match = $consumoSemanal->firstWhere('dia_num', $num);

            return [
                'dia' => $label,
                'galones' => $match ? round($match->galones, 2) : 0,
            ];
        })->values();

        return [
            'kpis' => $kpis,
            'consumo_por_tipo_destino' => $destino,
            'top_consumidores' => $topConsumers,
            'consumo_por_dia_semana' => $consumoPorDiaSemana,
        ];
    }

    private function findFuelOperation(int $actorId, string $operationId, bool $lock = false): ?RegistroCombustible
    {
        $query = RegistroCombustible::query()
            ->where('usuario_id', $actorId)
            ->where('client_operation_id', $operationId);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function fuelReplayResponse(RegistroCombustible $registro, string $operationHash): JsonResponse
    {
        if (! hash_equals((string) $registro->client_operation_hash, $operationHash)) {
            return response()->json([
                'message' => 'client_operation_id ya fue utilizado con un abastecimiento diferente.',
            ], 409);
        }

        return response()->json([
            'message' => 'El abastecimiento ya había sido registrado.',
            'registro' => $registro->load(['vehiculo', 'empleado']),
            'idempotent_replay' => true,
            'idempotency_protected' => true,
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function fuelOperationPayload(array $validated, int $warehouseId): array
    {
        return [
            'bodega_id' => $warehouseId,
            'vehiculo_id' => isset($validated['vehiculo_id']) ? (int) $validated['vehiculo_id'] : null,
            'empleado_id' => isset($validated['empleado_id']) ? (int) $validated['empleado_id'] : null,
            'tercero_nombre' => $validated['tercero_nombre'] ?? null,
            'placa_manual' => $validated['placa_manual'] ?? null,
            'tipo_destino' => $validated['tipo_destino'],
            'tipo_combustible' => $validated['tipo_combustible'],
            'cantidad_galones' => (float) $validated['cantidad_galones'],
            'valor_total' => isset($validated['valor_total']) ? (float) $validated['valor_total'] : 0.0,
            'horometro_actual' => isset($validated['horometro_actual']) ? (float) $validated['horometro_actual'] : null,
            'kilometraje_actual' => isset($validated['kilometraje_actual']) ? (float) $validated['kilometraje_actual'] : null,
            'estacion_servicio' => $validated['estacion_servicio'] ?? null,
            'notas' => $validated['notas'] ?? null,
            'labor' => $validated['labor'] ?? null,
        ];
    }

    private function fuelServerError(Throwable $exception, int $actorId, ?string $operationId): JsonResponse
    {
        $errorId = (string) Str::uuid();
        Log::error('Fuel registration failed.', [
            'error_id' => $errorId,
            'actor_id' => $actorId,
            'client_operation_id' => $operationId,
            'exception' => $exception,
        ]);

        return response()->json([
            'message' => 'Error interno al registrar abastecimiento.',
            'error_id' => $errorId,
        ], 500);
    }
}
