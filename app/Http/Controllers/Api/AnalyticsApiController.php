<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RegistroCombustible;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use App\Models\Vehiculo;
use App\Models\OrdenTrabajo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
        return response()->json([
            'summary' => $this->getSummary()->original,
            'fuelMonthly' => $this->getFuelMonthly()->original,
            'maintenanceByVehicle' => $this->getMaintenanceByVehicle()->original,
            'fuelStock' => $this->getFuelStock()->original,
            'fuelHistory15Days' => $this->getFuelConsumptionLast15Days(),
            'otStats' => $this->getOtStats(),
            'preopHoy' => $this->getPreopHoy(),
            'loansStats' => $this->getLoansStats(),
            'lowStock' => $this->getLowStock(),
            'alerts' => $this->getUnifiedAlerts(),
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
    public function getOtStats(): array
    {
        $porEstado = array_fill_keys(self::OT_ESTADOS, 0);
        $prioridades = array_fill_keys(self::OT_PRIORIDADES, 0);

        return [
            'porEstado' => $porEstado,
            'abiertas' => 0,
            'prioridades' => $prioridades,
            'sinMecanico' => 0,
        ];
    }

    public function getPreopHoy(): array
    {
        return [
            'total' => 0,
            'completados' => 0,
            'pendientes' => [],
        ];
    }

    public function getLoansStats(): array
    {
        return [
            'activos' => 0,
            'envejecidos' => 0,
            'items' => [],
        ];
    }

    public function getLowStock(): array
    {
        return [
            'count' => 0,
            'items' => [],
        ];
    }

    public function getUnifiedAlerts(): array
    {
        $counts = array_fill_keys(
            ['docs_vencidos', 'docs_por_vencer', 'servicios', 'stock', 'prestamos'],
            0
        );

        return [
            'total' => 0,
            'items' => [],
            'counts' => $counts,
        ];
    }
}
