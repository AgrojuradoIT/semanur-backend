<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TransaccionInventario;
use App\Models\OrdenTrabajo;
use App\Models\RegistroCombustible;
use App\Models\PrestamoHerramienta;
use App\Models\RespuestaListaChequeo;

class HistoryApiController extends Controller
{
    public function getHistoryAll(Request $request)
    {
        $limit = 100;

        $movimientos = TransaccionInventario::with(['producto', 'usuario'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $ordenes = OrdenTrabajo::with(['vehiculo', 'mecanico'])
            ->orderBy('fecha_inicio', 'desc')
            ->limit($limit)
            ->get();

        $combustible = RegistroCombustible::with(['vehiculo', 'usuario'])
            ->orderBy('fecha', 'desc')
            ->limit($limit)
            ->get();

        $prestamos = PrestamoHerramienta::with(['producto', 'mecanico', 'admin'])
            ->orderBy('fecha_prestamo', 'desc')
            ->limit($limit)
            ->get();

        $checklists = RespuestaListaChequeo::with(['listaChequeo', 'vehiculo', 'operador'])
            ->orderBy('fecha', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'movimientos' => $movimientos,
            'ordenes' => $ordenes,
            'combustible' => $combustible,
            'prestamos' => $prestamos,
            'checklists' => $checklists,
        ]);
    }
}
