<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CombustibleRequest;
use App\Models\RegistroCombustible;
use App\Models\TransaccionInventario;
use App\Services\CombustibleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CombustibleApiController extends Controller
{
    public function __construct(
        private readonly CombustibleService $combustibleService,
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

        $perPage = min((int) $request->get('per_page', 25), 100);
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

    public function store(CombustibleRequest $request)
    {
        try {
            return DB::transaction(function () use ($request) {
                $empleadoId = in_array($request->tipo_destino, ['vehiculo', 'maquinaria', 'equipo_menor']) ? $request->empleado_id : null;

                $producto = $this->combustibleService->resolveCombustibleProducto($request->tipo_combustible);

                if (!$producto) {
                    return response()->json([
                        'message' => "No se encontró el producto de combustible ({$request->tipo_combustible}). Verifique el inventario.",
                    ], 422);
                }

                if ($producto->producto_stock_actual < $request->cantidad_galones) {
                    return response()->json([
                        'message' => "Stock insuficiente de {$request->tipo_combustible}. Stock actual: {$producto->producto_stock_actual}",
                    ], 422);
                }

                [$refType, $refId, $notas] = $this->combustibleService->buildReferencia(
                    $request->tipo_destino,
                    $request->vehiculo_id,
                    $request->tercero_nombre,
                    $request->labor,
                );

                $bodegaId = $this->resolveDefaultBodegaId();

                $transaccion = TransaccionInventario::create([
                    'producto_id' => $producto->producto_id,
                    'bodega_id' => $bodegaId,
                    'usuario_id' => $request->user()->id,
                    'transaccion_tipo' => 'salida',
                    'transaccion_cantidad' => $request->cantidad_galones,
                    'transaccion_motivo' => 'Consumo de Combustible (Interno)',
                    'transaccion_referencia_id' => $refId,
                    'transaccion_referencia_type' => $refType,
                    'transaccion_notas' => $notas,
                ]);

                $registro = RegistroCombustible::create([
                    'vehiculo_id' => $request->vehiculo_id,
                    'empleado_id' => $empleadoId,
                    'tercero_nombre' => $request->tercero_nombre,
                    'tipo_destino' => $request->tipo_destino,
                    'tipo_combustible' => $request->tipo_combustible,
                    'usuario_id' => $request->user()->id,
                    'fecha' => Carbon::now(),
                    'cantidad_galones' => $request->cantidad_galones,
                    'valor_total' => $request->valor_total ?? 0,
                    'horometro_actual' => $request->horometro_actual,
                    'kilometraje_actual' => $request->kilometraje_actual,
                    'estacion_servicio' => $request->estacion_servicio,
                    'placa_manual' => $request->placa_manual,
                    'notas' => $request->notas,
                    'labor' => $request->labor,
                    'transaccion_id' => $transaccion->transaccion_id,
                ]);

                return response()->json([
                    'message' => 'Registro de combustible creado con éxito',
                    'registro' => $registro->load(['vehiculo', 'empleado']),
                ], 201);
            });
        } catch (\Exception $e) {
            \Log::error("Error en CombustibleApiController@store: " . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all(),
            ]);
            return response()->json([
                'message' => 'Error interno al registrar abastecimiento',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $registro = RegistroCombustible::find($id);

        if (!$registro) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        $validated = $request->validate([
            'tipo_combustible' => ['sometimes', 'in:gasolina,acpm'],
            'cantidad_galones' => ['sometimes', 'numeric', 'min:0.01'],
            'valor_total' => ['nullable', 'numeric', 'min:0'],
            'horometro_actual' => ['nullable', 'numeric'],
            'kilometraje_actual' => ['nullable', 'numeric'],
            'estacion_servicio' => ['nullable', 'string'],
            'notas' => ['nullable', 'string'],
            'labor' => ['nullable', 'string'],
            'tipo_destino' => ['sometimes', \Illuminate\Validation\Rule::in(['vehiculo', 'empleado', 'tercero', 'equipo_menor', 'maquinaria'])],
            'vehiculo_id' => ['nullable', 'exists:vehiculos,vehiculo_id'],
            'empleado_id' => ['nullable', 'exists:empleados,id'],
            'tercero_nombre' => ['nullable', 'string'],
            'placa_manual' => ['nullable', 'string'],
        ]);

        $registro->update($validated);

        return response()->json([
            'message' => 'Registro actualizado con éxito',
            'registro' => $registro->load(['vehiculo', 'empleado', 'usuario']),
        ]);
    }

    public function destroy($id)
    {
        $registro = RegistroCombustible::find($id);

        if (!$registro) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        return DB::transaction(function () use ($registro) {
            $transaccion = null;

            if ($registro->transaccion_id) {
                $transaccion = TransaccionInventario::find($registro->transaccion_id);
            }

            if (!$transaccion) {
                $transaccion = TransaccionInventario::where('transaccion_motivo', 'Consumo de Combustible (Interno)')
                    ->where('transaccion_tipo', 'salida')
                    ->where('transaccion_cantidad', $registro->cantidad_galones)
                    ->where('created_at', '>=', $registro->created_at->copy()->subMinute())
                    ->where('created_at', '<=', $registro->created_at->copy()->addMinute())
                    ->first();
            }

            if ($transaccion) {
                TransaccionInventario::create([
                    'producto_id' => $transaccion->producto_id,
                    'bodega_id' => $transaccion->bodega_id,
                    'usuario_id' => auth()->id(),
                    'transaccion_tipo' => 'ingreso',
                    'transaccion_cantidad' => $transaccion->transaccion_cantidad,
                    'transaccion_motivo' => 'Reversión por eliminación de tanqueo',
                    'transaccion_referencia_type' => 'combustible',
                    'transaccion_referencia_id' => $registro->registro_id,
                    'transaccion_notas' => "Reversión automática del registro #{$registro->registro_id}",
                ]);
            }

            $registro->delete();

            return response()->json([
                'message' => 'Registro eliminado' . ($transaccion ? ' y stock revertido' : ''),
            ]);
        });
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

            'consumo_diario' => $consumoDiario
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
                'galones' => round($topDia->total_galones, 2)
            ] : null
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
                    'registros' => $item->registros
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
                $nombre = ($item->vehiculo->placa ?? '') . ' (' . ($item->vehiculo->nombre ?? $item->vehiculo->marca ?? '') . ')';
            } elseif ($item->tipo_destino === 'empleado' && $item->tercero_nombre) {
                $nombre = $item->tercero_nombre . ($item->placa_manual ? " - {$item->placa_manual}" : '');
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
                'registros' => $item->registros
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
            7 => 'Sábado'
        ];

        $consumoPorDiaSemana = collect($diasLabels)->map(function ($label, $num) use ($consumoSemanal) {
            $match = $consumoSemanal->firstWhere('dia_num', $num);
            return [
                'dia' => $label,
                'galones' => $match ? round($match->galones, 2) : 0
            ];
        })->values();

        return [
            'kpis' => $kpis,
            'consumo_por_tipo_destino' => $destino,
            'top_consumidores' => $topConsumers,
            'consumo_por_dia_semana' => $consumoPorDiaSemana
        ];
    }

    private function resolveDefaultBodegaId(): ?int
    {
        return \App\Models\Bodega::value('bodega_id');
    }
}
