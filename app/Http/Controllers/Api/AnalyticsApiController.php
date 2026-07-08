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
    public function getDashboard()
    {
        return response()->json([
            'summary' => $this->getSummary()->original,
            'fuelMonthly' => $this->getFuelMonthly()->original,
            'maintenanceByVehicle' => $this->getMaintenanceByVehicle()->original,
            'fuelStock' => $this->getFuelStock()->original,
            'fuelHistory15Days' => $this->getFuelConsumptionLast15Days(),
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
        $stats = RegistroCombustible::select(
            DB::raw('MONTH(fecha) as month'),
            DB::raw('YEAR(fecha) as year'),
            DB::raw('SUM(cantidad_galones) as gallons'),
            DB::raw('SUM(valor_total) as cost')
        )
        ->where('fecha', '>=', Carbon::now()->subMonths(6))
        ->groupBy('year', 'month')
        ->orderBy('year', 'asc')
        ->orderBy('month', 'asc')
        ->get();

        return response()->json($stats);
    }

    public function getMaintenanceByVehicle()
    {
        $stats = DB::table('transaccion_inventarios')
            ->join('productos', 'transaccion_inventarios.producto_id', '=', 'productos.producto_id')
            ->join('orden_trabajos', 'transaccion_inventarios.transaccion_referencia_id', '=', 'orden_trabajos.orden_trabajo_id')
            ->join('vehiculos', 'orden_trabajos.vehiculo_id', '=', 'vehiculos.vehiculo_id')
            ->where('transaccion_referencia_type', 'OrdenTrabajo')
            ->select(
                'vehiculos.placa',
                DB::raw('SUM(transaccion_cantidad * producto_precio_costo) as total_cost')
            )
            ->groupBy('vehiculos.placa')
            ->orderBy('total_cost', 'desc')
            ->take(5)
            ->get();

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
}
