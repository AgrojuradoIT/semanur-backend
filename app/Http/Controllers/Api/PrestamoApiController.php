<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\PrestamoHerramienta;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrestamoApiController extends Controller
{
    // Removed resolveMecanicoUserId as we now strictly use empleado_id from the frontend

    public function index(Request $request)
    {
        $query = PrestamoHerramienta::with(['producto', 'mecanico', 'admin'])
            ->orderBy('fecha_prestamo', 'desc');

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'producto_id' => 'required|exists:productos,producto_id',
            'mecanico_id' => 'required|exists:empleados,id',
            'prestamo_cantidad' => 'required|numeric|min:0.01',
            'notas' => 'nullable|string',
        ]);

        $mecanicoId = (int) $request->mecanico_id;

        return DB::transaction(function () use ($request, $mecanicoId) {
            $producto = Producto::find($request->producto_id);

            if ($producto->producto_stock_actual < $request->prestamo_cantidad) {
                return response()->json(['message' => 'Stock insuficiente para el prestamo'], 422);
            }

            $prestamo = PrestamoHerramienta::create([
                'producto_id' => $request->producto_id,
                'mecanico_id' => $mecanicoId,
                'admin_id' => $request->user()->id,
                'prestamo_cantidad' => $request->prestamo_cantidad,
                'fecha_prestamo' => Carbon::now(),
                'estado' => 'prestado',
                'notas' => $request->notas,
            ]);

            TransaccionInventario::create([
                'producto_id' => $request->producto_id,
                'usuario_id' => $request->user()->id,
                'transaccion_tipo' => 'salida',
                'transaccion_cantidad' => $request->prestamo_cantidad,
                'transaccion_motivo' => 'Prestamo de Herramienta',
                'transaccion_referencia_id' => $prestamo->prestamo_id,
                'transaccion_referencia_type' => 'PrestamoHerramienta',
                'transaccion_notas' => "Prestamo ID: {$prestamo->prestamo_id} a empleado ID: {$mecanicoId}",
            ]);

            $producto->refresh();

            return response()->json([
                'message' => 'Prestamo registrado correctamente',
                'prestamo' => $prestamo,
                'nuevo_stock' => $producto->producto_stock_actual,
            ]);
        });
    }

    public function devolver(Request $request, $id)
    {
        $request->validate([
            'estado' => 'required|in:devuelto,danado,dañado,perdido',
            'notas' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $prestamo = PrestamoHerramienta::findOrFail($id);

            if ($prestamo->estado !== 'prestado') {
                return response()->json(['message' => 'Este prestamo ya fue procesado'], 422);
            }

            $prestamo->estado = $request->estado;
            $prestamo->fecha_devolucion = Carbon::now();
            $prestamo->notas = $request->notas ?? $prestamo->notas;
            $prestamo->save();

            if ($request->estado === 'devuelto') {
                TransaccionInventario::create([
                    'producto_id' => $prestamo->producto_id,
                    'usuario_id' => $request->user()->id,
                    'transaccion_tipo' => 'ingreso',
                    'transaccion_cantidad' => $prestamo->prestamo_cantidad,
                    'transaccion_motivo' => 'Devolucion de Herramienta',
                    'transaccion_referencia_id' => $prestamo->prestamo_id,
                    'transaccion_referencia_type' => 'PrestamoHerramienta',
                ]);
            }

            return response()->json([
                'message' => 'Devolucion procesada correctamente',
                'prestamo' => $prestamo,
            ]);
        });
    }
}
