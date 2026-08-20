<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrdenTrabajo;
use App\Models\PreoperacionalDailyForm;
use App\Models\PreoperacionalSemana;
use App\Models\PreoperacionalTemplate;
use App\Models\PrestamoHerramienta;
use App\Models\Producto;
use App\Models\RegistroCombustible;
use App\Models\TransaccionInventario;
use App\Models\Vehiculo;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsApiController extends Controller
{
    // ─── Aggregation constants (design SPEC-005/006) ────────────────────
    private const OT_ESTADOS = ['Abierta', 'En Progreso', 'Pendiente Auditoria', 'Aprobada', 'Cerrada'];
    private const OT_PRIORIDADES = ['Alta', 'Media', 'Baja'];
    private const OT_OPEN_STATES = ['Abierta', 'En Progreso'];
    private const DOC_SOON_DAYS = 30;
    private const OIL_SOON_THRESHOLD_KM = 500;
    private const HOROM_SOON_THRESHOLD_H = 50;
    private const ALERT_SEVERITY_RANK = ['vencido' => 0, 'proximo' => 1, 'informativo' => 2];
    private const ALERT_CAP = 8;

    public function getDashboard()
    {
        $loansStats = $this->getLoansStats();
        $lowStock = $this->getLowStock();

        return response()->json([
            'summary' => $this->getSummary()->original,
            'fuelMonthly' => $this->getFuelMonthly()->original,
            'maintenanceByVehicle' => $this->getMaintenanceByVehicle()->original,
            'fuelStock' => $this->getFuelStock()->original,
            'fuelHistory15Days' => $this->getFuelConsumptionLast15Days(),
            'otStats' => $this->getOtStats(),
            'preopHoy' => $this->getPreopHoy(),
            'loansStats' => $loansStats,
            'lowStock' => $lowStock,
            'liveSessions' => $this->getLiveSessions(),
            'recentActivity' => $this->getRecentActivity(),
            'alerts' => $this->getUnifiedAlerts($lowStock, $loansStats),
        ]);
    }

    public function getSummary()
    {
        $totalFuel = RegistroCombustible::sum('valor_total');
        
        // Costo de repuestos (salidas de inventario vinculadas a OT)
        $totalMaintenance = DB::table('transaccion_inventarios')
            ->join('productos', 'transaccion_inventarios.producto_id', '=', 'productos.producto_id')
            ->where('transaccion_referencia_type', 'OrdenTrabajo')
            ->select(DB::raw('SUM(transaccion_cantidad * producto_precio_costo) as total'))
            ->first()->total ?? 0;

        return response()->json([
            'total_fuel_cost' => (float)$totalFuel,
            'total_maintenance_cost' => (float)$totalMaintenance,
            'vehicle_count' => Vehiculo::count(),
            'open_orders' => OrdenTrabajo::where('estado', '!=', 'Cerrada')->count(),
        ]);
    }

    public function getFuelMonthly()
    {
        // Driver-agnostic: avoid MySQL MONTH()/YEAR() which fail on sqlite (test env).
        // Pull only the relevant columns at the row level, then group by year-month in PHP.
        $cutoff = Carbon::now()->subMonths(6);

        $rows = RegistroCombustible::where('fecha', '>=', $cutoff)
            ->select('fecha', 'cantidad_galones', 'valor_total')
            ->get();

        $stats = $rows
            ->groupBy(fn ($r) => $r->fecha->format('Y-m'))
            ->map(function ($group, $ym) {
                return [
                    'year' => (int) substr($ym, 0, 4),
                    'month' => (int) substr($ym, 5, 2),
                    'gallons' => round((float) $group->sum('cantidad_galones'), 2),
                    'cost' => round((float) $group->sum('valor_total'), 2),
                ];
            })
            ->sortBy([['year', 'asc'], ['month', 'asc']])
            ->values();

        return response()->json($stats);
    }

    public function getMaintenanceByVehicle()
    {
        // Driver-agnostic: avoid MySQL SUM(transaccion_cantidad * producto_precio_costo).
        // Pull joined rows, compute cost in PHP, group by placa in PHP.
        $rows = DB::table('transaccion_inventarios')
            ->join('productos', 'transaccion_inventarios.producto_id', '=', 'productos.producto_id')
            ->join('orden_trabajos', 'transaccion_inventarios.transaccion_referencia_id', '=', 'orden_trabajos.orden_trabajo_id')
            ->join('vehiculos', 'orden_trabajos.vehiculo_id', '=', 'vehiculos.vehiculo_id')
            ->where('transaccion_referencia_type', 'OrdenTrabajo')
            ->select(
                'vehiculos.placa',
                'transaccion_inventarios.transaccion_cantidad',
                'productos.producto_precio_costo'
            )
            ->get();

        $stats = $rows
            ->groupBy('placa')
            ->map(function ($group, $placa) {
                $total = 0.0;
                foreach ($group as $row) {
                    $total += (float) $row->transaccion_cantidad * (float) $row->producto_precio_costo;
                }
                return (object) [
                    'placa' => $placa,
                    'total_cost' => round($total, 2),
                ];
            })
            ->sortByDesc('total_cost')
            ->take(5)
            ->values();

        return response()->json($stats);
    }

    /**
     * Retorna los productos de tipo combustible con su stock actual.
     * Permite al dashboard mostrar Gasolina vs ACPM, etc.
     */
    public function getFuelStock()
    {
        // Solo productos de la categoría "Combustible"
        $fuelProducts = Producto::join('categorias', 'productos.categoria_id', '=', 'categorias.categoria_id')
            ->whereRaw('LOWER(categorias.categoria_nombre) LIKE ?', ['%combustible%'])
            ->select(
                'productos.producto_id',
                'productos.producto_nombre',
                'productos.producto_sku',
                'productos.producto_stock_actual',
                'productos.capacidad_maxima',
                'productos.producto_unidad_medida',
                'productos.producto_alerta_stock_minimo'
            )
            ->orderBy('productos.producto_nombre')
            ->get()
            ->map(function ($p) {
                $p->porcentaje_nivel = $p->capacidad_maxima > 0
                    ? round(($p->producto_stock_actual / $p->capacidad_maxima) * 100, 1)
                    : null;
                return $p;
            });

        return response()->json($fuelProducts);
    }

    /**
     * Retorna el consumo diario de gasolina y ACPM de los últimos 15 días.
     */
    public function getFuelConsumptionLast15Days()
    {
        $startDate = Carbon::now()->subDays(14)->startOfDay();
        
        $records = RegistroCombustible::select(
            DB::raw('DATE(fecha) as date_label'),
            'tipo_combustible',
            DB::raw('SUM(cantidad_galones) as gallons')
        )
        ->where('fecha', '>=', $startDate)
        ->groupBy('date_label', 'tipo_combustible')
        ->orderBy('date_label', 'asc')
        ->get();

        // Generar arreglo continuo de los últimos 15 días
        $data = [];
        for ($i = 14; $i >= 0; $i--) {
            $dateStr = Carbon::now()->subDays($i)->format('Y-m-d');
            $data[$dateStr] = [
                'date' => $dateStr,
                'gasolina' => 0.0,
                'acpm' => 0.0,
                'day_name' => Carbon::now()->subDays($i)->locale('es')->minDayName, // L, M, M, J, V, S, D
                'day_number' => Carbon::now()->subDays($i)->format('d'),
            ];
        }

        foreach ($records as $record) {
            $date = $record->date_label;
            if (isset($data[$date])) {
                $fuelType = strtolower($record->tipo_combustible);
                if ($fuelType === 'gasolina') {
                    $data[$date]['gasolina'] = round((float)$record->gallons, 2);
                } elseif ($fuelType === 'acpm' || $fuelType === 'diesel') {
                    $data[$date]['acpm'] = round((float)$record->gallons, 2);
                }
            }
        }

        return array_values($data);
    }

    // ─── STABILIZATION STUBS (PR1, BFF foundation) ───────────────────────
    // Empty bodies returning the EXACT shape asserted by
    // tests/Feature/Api/DashboardAnalyticsTest.php on empty DB.
    // Real aggregation logic is tasks 1.2–1.7 of the dashboard-rework plan.

    /**
     * SPEC-001 / DA-1.2: OT counts grouped by estado + open-only abiertas
     * + open-only prioridades + sinMecanico (unassigned) count.
     *
     * Driver-agnostic: avoid MySQL-specific aggregations (uses SQLite in tests).
     * GroupBy/count via Eloquent, 0-fill missing keys in PHP.
     */
    public function getOtStats(): array
    {
        // 1. porEstado — group all OTs by estado, 0-fill the 5 canonical keys.
        $porEstado = array_fill_keys(self::OT_ESTADOS, 0);
        $estadoCounts = OrdenTrabajo::query()
            ->groupBy('estado')
            ->selectRaw('estado, COUNT(*) as c')
            ->pluck('c', 'estado');
        foreach ($estadoCounts as $estado => $count) {
            if (array_key_exists($estado, $porEstado)) {
                $porEstado[$estado] = (int) $count;
            }
        }

        // 2. abiertas — count OTs in OT_OPEN_STATES only (Abierta, En Progreso).
        $abiertas = (int) OrdenTrabajo::query()
            ->whereIn('estado', self::OT_OPEN_STATES)
            ->count();

        // 3. prioridades — group by prioridad, filtered to OT_OPEN_STATES only.
        $prioridades = array_fill_keys(self::OT_PRIORIDADES, 0);
        $prioridadCounts = OrdenTrabajo::query()
            ->whereIn('estado', self::OT_OPEN_STATES)
            ->groupBy('prioridad')
            ->selectRaw('prioridad, COUNT(*) as c')
            ->pluck('c', 'prioridad');
        foreach ($prioridadCounts as $prioridad => $count) {
            if (array_key_exists($prioridad, $prioridades)) {
                $prioridades[$prioridad] = (int) $count;
            }
        }

        // 4. sinMecanico — count OTs where mecanico_asignado_id IS NULL.
        $sinMecanico = (int) OrdenTrabajo::query()
            ->whereIn('estado', self::OT_OPEN_STATES)
            ->whereNull('mecanico_asignado_id')
            ->count();

        // 5. tendencia — 7-day daily count of active open OTs from real database records (single query).
        $openOts = OrdenTrabajo::query()
            ->whereIn('estado', self::OT_OPEN_STATES)
            ->select('created_at', 'fecha_fin')
            ->get();

        $otTendencia = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayStart = Carbon::today()->subDays($i)->startOfDay();
            $dayEnd = Carbon::today()->subDays($i)->endOfDay();
            $count = $openOts->filter(function ($ot) use ($dayStart, $dayEnd) {
                if ($ot->created_at > $dayEnd) {
                    return false;
                }
                if ($ot->fecha_fin && $ot->fecha_fin < $dayStart) {
                    return false;
                }
                return true;
            })->count();
            $otTendencia[] = $count;
        }

        return [
            'porEstado' => $porEstado,
            'abiertas' => $abiertas,
            'prioridades' => $prioridades,
            'sinMecanico' => $sinMecanico,
            'tendencia' => $otTendencia,
        ];
    }

    public function getPreopHoy(): array
    {
        $fecha = Carbon::today();
        $diaSemana = $this->preopDiaSemana($fecha);
        $semanaInicio = $fecha->copy()->startOfWeek(Carbon::MONDAY)->toDateString();

        // Mirror PreoperacionalV2Controller::pendientesHoy() with four bounded
        // preloads so every vehicle can be resolved in memory without N+1 queries.
        $vehiculos = Vehiculo::query()
            ->whereNotNull('tipo')
            ->select('vehiculo_id', 'placa', 'tipo')
            ->orderBy('placa')
            ->get();
        $templatesByTipo = PreoperacionalTemplate::query()
            ->where('activo', true)
            ->select('id', 'codigo', 'tipo_vehiculo')
            ->get()
            ->groupBy('tipo_vehiculo');
        $semanas = PreoperacionalSemana::query()
            ->whereDate('semana_inicio', $semanaInicio)
            ->select('id', 'vehiculo_id', 'template_id')
            ->get();
        $completedSemanaIds = PreoperacionalDailyForm::query()
            ->whereIn('semana_id', $semanas->pluck('id'))
            ->where('dia_semana', $diaSemana)
            ->where('completado', true)
            ->pluck('semana_id')
            ->flip();

        $semanasIndex = $semanas->keyBy(
            fn (PreoperacionalSemana $semana) => $semana->vehiculo_id . '-' . $semana->template_id
        );
        $total = 0;
        $completados = 0;
        $pendientes = [];

        foreach ($vehiculos as $vehiculo) {
            $template = $templatesByTipo->get($vehiculo->tipo)?->first()
                ?? $templatesByTipo->get('generico')?->first();

            if (! $template) {
                continue;
            }

            $total++;
            $semana = $semanasIndex->get($vehiculo->vehiculo_id . '-' . $template->id);

            if ($semana && $completedSemanaIds->has($semana->id)) {
                $completados++;

                continue;
            }

            if (count($pendientes) < self::ALERT_CAP) {
                $pendientes[] = [
                    'vehiculo_id' => $vehiculo->vehiculo_id,
                    'placa' => $vehiculo->placa,
                    'tipo' => $vehiculo->tipo,
                ];
            }
        }

        // tendencia — 7-day daily count of completed preoperacionales from real database records (single query).
        $sevenDaysAgo = Carbon::today()->subDays(6)->toDateString();
        $completedFormsByDate = PreoperacionalDailyForm::query()
            ->where('completado', true)
            ->whereDate('fecha', '>=', $sevenDaysAgo)
            ->select('fecha')
            ->get()
            ->groupBy(fn ($form) => $form->fecha ? $form->fecha->toDateString() : '');

        $preopTendencia = [];
        for ($i = 6; $i >= 0; $i--) {
            $dateStr = Carbon::today()->subDays($i)->toDateString();
            $preopTendencia[] = isset($completedFormsByDate[$dateStr]) ? $completedFormsByDate[$dateStr]->count() : 0;
        }

        return [
            'total' => $total,
            'completados' => $completados,
            'pendientes' => $pendientes,
            'tendencia' => $preopTendencia,
        ];
    }

    private function preopDiaSemana(Carbon $fecha): string
    {
        return match ($fecha->dayOfWeekIso) {
            1 => 'lunes',
            2 => 'martes',
            3 => 'miercoles',
            4 => 'jueves',
            5 => 'viernes',
            6 => 'sabado',
            7 => 'domingo',
        };
    }

    public function getLoansStats(): array
    {
        $now = Carbon::now();
        $agedBefore = $now->copy()->subDays(7);
        $activeLoans = PrestamoHerramienta::query()->where('estado', 'prestado');

        $items = (clone $activeLoans)
            ->where('fecha_prestamo', '<', $agedBefore)
            ->with([
                'producto:producto_id,producto_nombre',
                'mecanico:id,nombres,apellidos',
            ])
            ->orderBy('fecha_prestamo')
            ->limit(self::ALERT_CAP)
            ->get()
            ->each(function (PrestamoHerramienta $prestamo) use ($now): void {
                $prestamo->setAttribute('dias', (int) $prestamo->fecha_prestamo->diffInDays($now));
            });

        // tendencia — 7-day daily count of active tool loans from real database records (single query).
        $activeLoansForTrend = (clone $activeLoans)
            ->select('fecha_prestamo', 'fecha_devolucion')
            ->get();

        $loansTendencia = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayStart = Carbon::today()->subDays($i)->startOfDay();
            $dayEnd = Carbon::today()->subDays($i)->endOfDay();
            $count = $activeLoansForTrend->filter(function ($loan) use ($dayStart, $dayEnd) {
                if ($loan->fecha_prestamo > $dayEnd) {
                    return false;
                }
                if ($loan->fecha_devolucion && $loan->fecha_devolucion < $dayStart) {
                    return false;
                }
                return true;
            })->count();
            $loansTendencia[] = $count;
        }

        return [
            'activos' => (clone $activeLoans)->count(),
            'envejecidos' => (clone $activeLoans)->where('fecha_prestamo', '<', $agedBefore)->count(),
            'items' => $items,
            'tendencia' => $loansTendencia,
        ];
    }

    private function getLowStock(): array
    {
        $lowStock = Producto::query()
            ->whereColumn('producto_stock_actual', '<=', 'producto_alerta_stock_minimo')
            ->where('producto_alerta_stock_minimo', '>', 0);

        return [
            'count' => (clone $lowStock)->count(),
            'items' => (clone $lowStock)
                ->select([
                    'producto_id',
                    'producto_sku',
                    'producto_nombre',
                    'producto_unidad_medida',
                    'producto_stock_actual',
                    'producto_alerta_stock_minimo',
                ])
                ->orderByRaw('1.0 * producto_stock_actual / producto_alerta_stock_minimo asc')
                ->orderBy('producto_id')
                ->limit(self::ALERT_CAP)
                ->get(),
        ];
    }

    private function getLiveSessions(): array
    {
        $activeSessions = WorkSession::query()->whereNull('fecha_fin');
        $now = Carbon::now();

        $items = (clone $activeSessions)
            ->with([
                'empleado:id,nombres,apellidos',
                'ordenTrabajo:orden_trabajo_id,vehiculo_id',
                'ordenTrabajo.vehiculo:vehiculo_id,placa',
            ])
            ->orderByDesc('fecha_inicio')
            ->limit(self::ALERT_CAP)
            ->get()
            ->map(function (WorkSession $session) use ($now): array {
                return [
                    'sesion_id' => $session->sesion_id,
                    'fecha_inicio' => $this->serializeBogota($session->fecha_inicio),
                    'elapsed_min' => max(0, $session->fecha_inicio->diffInMinutes($now)),
                    'empleado' => [
                        'id' => $session->empleado?->id,
                        'nombre' => trim(($session->empleado?->nombres ?? '').' '.($session->empleado?->apellidos ?? '')),
                    ],
                    'ordenTrabajo' => [
                        'orden_trabajo_id' => $session->ordenTrabajo?->orden_trabajo_id,
                        'vehiculo' => [
                            'vehiculo_id' => $session->ordenTrabajo?->vehiculo?->vehiculo_id,
                            'placa' => $session->ordenTrabajo?->vehiculo?->placa,
                        ],
                    ],
                ];
            })
            ->values()
            ->all();

        return [
            'total' => (clone $activeSessions)->count(),
            'items' => $items,
        ];
    }

    private function getRecentActivity(): array
    {
        $workOrders = OrdenTrabajo::query()
            ->with('vehiculo:vehiculo_id,placa')
            ->select('orden_trabajo_id', 'vehiculo_id', 'estado', 'descripcion', 'created_at')
            ->orderByDesc('created_at')
            ->limit(self::ALERT_CAP)
            ->get()
            ->map(fn (OrdenTrabajo $ordenTrabajo): array => [
                'type' => 'ot',
                'id' => $ordenTrabajo->orden_trabajo_id,
                'vehiculo_id' => $ordenTrabajo->vehiculo_id,
                'placa' => $ordenTrabajo->vehiculo?->placa,
                'titulo' => $ordenTrabajo->descripcion ?: "OT #{$ordenTrabajo->orden_trabajo_id}",
                'descripcion' => trim(($ordenTrabajo->vehiculo?->placa ?? 'Sin vehículo').' · '.$ordenTrabajo->estado),
                'at' => $ordenTrabajo->created_at,
                'link' => ['name' => 'work-orders'],
            ]);
        $movimientos = TransaccionInventario::query()
            ->with('producto:producto_id,producto_nombre')
            ->select('transaccion_id', 'producto_id', 'transaccion_tipo', 'transaccion_cantidad', 'created_at')
            ->orderByDesc('created_at')
            ->limit(self::ALERT_CAP)
            ->get()
            ->map(fn (TransaccionInventario $movimiento): array => [
                'type' => 'movimiento',
                'id' => $movimiento->transaccion_id,
                'producto_id' => $movimiento->producto_id,
                'producto_nombre' => $movimiento->producto?->producto_nombre,
                'titulo' => $movimiento->producto?->producto_nombre ?? "Movimiento #{$movimiento->transaccion_id}",
                'descripcion' => ucfirst($movimiento->transaccion_tipo).' de inventario',
                'at' => $movimiento->created_at,
                'link' => ['name' => 'inventory'],
            ]);
        $tanqueos = RegistroCombustible::query()
            ->with('vehiculo:vehiculo_id,placa')
            ->select('registro_id', 'vehiculo_id', 'tipo_combustible', 'cantidad_galones', 'fecha')
            ->orderByDesc('fecha')
            ->limit(self::ALERT_CAP)
            ->get()
            ->map(fn (RegistroCombustible $tanqueo): array => [
                'type' => 'tanqueo',
                'id' => $tanqueo->registro_id,
                'vehiculo_id' => $tanqueo->vehiculo_id,
                'placa' => $tanqueo->vehiculo?->placa,
                'titulo' => 'Tanqueo '.($tanqueo->vehiculo?->placa ?? 'sin vehículo'),
                'descripcion' => rtrim(rtrim((string) $tanqueo->cantidad_galones, '0'), '.').' gal '.strtoupper((string) $tanqueo->tipo_combustible),
                'at' => $tanqueo->fecha,
                'link' => ['name' => 'fuel'],
            ]);

        return $workOrders
            ->concat($movimientos)
            ->concat($tanqueos)
            ->sortByDesc(fn (array $item) => $item['at']->getTimestamp())
            ->take(self::ALERT_CAP)
            ->map(function (array $item): array {
                $item['at'] = $this->serializeBogota($item['at']);

                return $item;
            })
            ->values()
            ->all();
    }

    private function serializeBogota(Carbon $date): string
    {
        return $date->copy()->setTimezone('America/Bogota')->toIso8601String();
    }

    private function getUnifiedAlerts(array $lowStock, array $loansStats): array
    {
        $counts = array_fill_keys(
            ['docs_vencidos', 'docs_por_vencer', 'servicios', 'stock', 'prestamos'],
            0
        );

        $now = Carbon::now();
        $vehiculos = Vehiculo::query()
            ->select([
                'vehiculo_id',
                'placa',
                'metodo_seguimiento',
                'fecha_vencimiento_soat',
                'fecha_vencimiento_tecnomecanica',
                'kilometraje_actual',
                'kilometraje_proximo_mantenimiento',
                'horometro_actual',
                'horometro_proximo_mantenimiento',
            ])
            ->orderBy('vehiculo_id')
            ->get();
        $documentoAlerts = $this->buildDocumentoAlerts($vehiculos, $now, $counts);
        $servicioAlerts = $this->buildServicioAlerts($vehiculos, $counts);
        $stockAlerts = $this->buildStockAlerts($lowStock['items']);
        $prestamoAlerts = $this->buildPrestamoAlerts($loansStats['items']);
        $counts['stock'] = (int) $lowStock['count'];
        $counts['prestamos'] = (int) $loansStats['envejecidos'];
        $items = $this->sortAlertItems([
            ...$documentoAlerts,
            ...$servicioAlerts,
            ...$stockAlerts,
            ...$prestamoAlerts,
        ]);

        return [
            'total' => array_sum($counts),
            'items' => array_slice($items, 0, self::ALERT_CAP),
            'counts' => $counts,
        ];
    }

    /**
     * Port of DashboardPage.vue legacy rules L1-L5. Browser `new Date('YYYY-MM-DD')`
     * parses a date-only value at UTC midnight, not at Bogota midnight. Rebuild that
     * exact UTC instant from the persisted date-only string before applying the legacy
     * ceil((date - now) / 864e5) formula against the server's timezone-safe `now`.
     */
    private function buildDocumentoAlerts(iterable $vehiculos, Carbon $now, array &$counts): array
    {
        $alerts = [];

        foreach ($vehiculos as $vehiculo) {
            foreach ([
                'soat' => ['field' => 'fecha_vencimiento_soat', 'label' => 'SOAT'],
                'tecnomecanica' => ['field' => 'fecha_vencimiento_tecnomecanica', 'label' => 'Tecnomecánica'],
            ] as $subtipo => $documento) {
                $fecha = $vehiculo->{$documento['field']};

                if (! $fecha) {
                    continue;
                }

                $dias = $this->legacyDateOnlyDaysUntil($fecha, $now);

                if ($dias === null) {
                    continue;
                }

                if ($dias > self::DOC_SOON_DAYS) {
                    continue;
                }

                $severity = $dias < 0 ? 'vencido' : 'proximo';
                $counts[$dias < 0 ? 'docs_vencidos' : 'docs_por_vencer']++;
                $alerts[] = [
                    'tipo' => 'documento',
                    'subtipo' => $subtipo,
                    'severity' => $severity,
                    'entidad' => [
                        'kind' => 'vehiculo',
                        'id' => $vehiculo->vehiculo_id,
                        'label' => $vehiculo->placa,
                    ],
                    'detalle' => $dias < 0 ? 'Vencido ' . abs($dias) . 'd' : $dias . 'd',
                    'link' => ['name' => 'fleet'],
                    '_urgencia' => $dias,
                ];
            }
        }

        return $alerts;
    }

    /**
     * Reproduces DashboardPage.vue `daysUntil()` for persisted YYYY-MM-DD fields.
     * The model casts dates in the application timezone, so using that Carbon value
     * directly would shift the legacy browser's UTC-midnight instant after 19:00 BOG.
     */
    private function legacyDateOnlyDaysUntil(mixed $fecha, Carbon $now): ?int
    {
        $dateOnly = $fecha instanceof Carbon ? $fecha->toDateString() : (string) $fecha;

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateOnly)) {
            return null;
        }

        try {
            $legacyUtcMidnight = Carbon::createFromFormat('!Y-m-d', $dateOnly, 'UTC');
        } catch (\Throwable) {
            return null;
        }

        if ($legacyUtcMidnight->toDateString() !== $dateOnly) {
            return null;
        }

        return (int) ceil($now->diffInDays($legacyUtcMidnight, false));
    }

    /**
     * Routes service alerts by their tracking axis. The kilometre branch ports
     * legacy rules L6-L7; horometre vehicles use the established 50-hour rule.
     */
    private function buildServicioAlerts(iterable $vehiculos, array &$counts): array
    {
        $alerts = [];

        foreach ($vehiculos as $vehiculo) {
            if ($vehiculo->metodo_seguimiento === 'kilometraje') {
                $proximo = (float) ($vehiculo->kilometraje_proximo_mantenimiento ?? 0);
                $actual = (float) ($vehiculo->kilometraje_actual ?? 0);
                $umbral = self::OIL_SOON_THRESHOLD_KM;
                $subtipo = 'kilometraje';
                $unidad = 'km';
            } elseif ($vehiculo->metodo_seguimiento === 'horometro') {
                $proximo = (float) ($vehiculo->horometro_proximo_mantenimiento ?? 0);
                $actual = (float) ($vehiculo->horometro_actual ?? 0);
                $umbral = self::HOROM_SOON_THRESHOLD_H;
                $subtipo = 'horometro';
                $unidad = 'h';
            } else {
                continue;
            }

            if ($proximo <= 0) {
                continue;
            }

            $restante = (int) round($proximo - $actual);

            if ($restante > $umbral) {
                continue;
            }

            $counts['servicios']++;
            $alerts[] = [
                'tipo' => 'servicio',
                'subtipo' => $subtipo,
                'severity' => $restante <= 0 ? 'vencido' : 'proximo',
                'entidad' => [
                    'kind' => 'vehiculo',
                    'id' => $vehiculo->vehiculo_id,
                    'label' => $vehiculo->placa,
                ],
                'detalle' => $restante <= 0
                    ? abs($restante) . " $unidad venc."
                    : "$restante $unidad",
                'link' => ['name' => 'fleet'],
                '_urgencia' => $restante,
            ];
        }

        return $alerts;
    }

    private function buildStockAlerts(iterable $productos): array
    {
        $alerts = [];

        foreach ($productos as $producto) {
            $stock = (float) $producto->producto_stock_actual;
            $minimo = (float) $producto->producto_alerta_stock_minimo;
            $severity = $stock <= $minimo / 2 ? 'vencido' : 'proximo';
            $alerts[] = [
                'tipo' => 'stock',
                'subtipo' => 'bajo',
                'severity' => $severity,
                'entidad' => [
                    'kind' => 'producto',
                    'id' => $producto->producto_id,
                    'label' => $producto->producto_nombre,
                ],
                'detalle' => "$stock de $minimo unidades",
                'link' => ['name' => 'inventory'],
                '_urgencia' => $stock / $minimo,
            ];
        }

        return $alerts;
    }

    private function buildPrestamoAlerts(iterable $prestamos): array
    {
        $alerts = [];

        foreach ($prestamos as $prestamo) {
            $dias = (int) $prestamo->dias;
            $alerts[] = [
                'tipo' => 'prestamo',
                'subtipo' => 'envejecido',
                'severity' => $dias >= 30 ? 'vencido' : 'proximo',
                'entidad' => [
                    'kind' => 'prestamo',
                    'id' => $prestamo->prestamo_id,
                    'label' => $prestamo->producto?->producto_nombre ?? "Préstamo #$prestamo->prestamo_id",
                ],
                'detalle' => "$dias d prestado",
                'link' => ['name' => 'loans'],
                '_urgencia' => -$dias,
            ];
        }

        return $alerts;
    }

    private function sortAlertItems(array $alerts): array
    {
        usort($alerts, function (array $left, array $right): int {
            $severity = self::ALERT_SEVERITY_RANK[$left['severity']]
                <=> self::ALERT_SEVERITY_RANK[$right['severity']];

            if ($severity !== 0) {
                return $severity;
            }

            $urgencia = $left['_urgencia'] <=> $right['_urgencia'];

            if ($urgencia !== 0) {
                return $urgencia;
            }

            return [$left['tipo'], $left['subtipo'], $left['entidad']['id']]
                <=> [$right['tipo'], $right['subtipo'], $right['entidad']['id']];
        });

        return array_map(function (array $alert): array {
            unset($alert['_urgencia']);

            return $alert;
        }, $alerts);
    }
}
