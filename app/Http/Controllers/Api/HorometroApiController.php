<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RegistroHorometro;
use App\Models\Vehiculo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HorometroApiController extends Controller
{
    public function index($vehiculoId)
    {
        $registros = RegistroHorometro::with('usuario')
            ->where('vehiculo_id', $vehiculoId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($registros);
    }

    public function store(Request $request)
    {
        $request->validate([
            'vehiculo_id' => 'required|exists:vehiculos,vehiculo_id',
            'valor_nuevo' => 'required|numeric|min:0',
            'notas' => 'nullable|string',
        ]);

        $vehiculo = Vehiculo::findOrFail($request->vehiculo_id);

        if ($request->valor_nuevo < $vehiculo->horometro_actual) {
            return response()->json([
                'message' => 'El valor nuevo no puede ser menor al valor actual (' . $vehiculo->horometro_actual . ')'
            ], 422);
        }

        try {
            return DB::transaction(function () use ($request, $vehiculo) {
                $registro = new RegistroHorometro();
                $registro->vehiculo_id = $request->vehiculo_id;
                $registro->valor_anterior = $vehiculo->horometro_actual;
                $registro->valor_nuevo = $request->valor_nuevo;
                $registro->usuario_id = $request->user()->id;
                $registro->notas = $request->notas;
                $registro->save();

                $vehiculo->horometro_actual = $request->valor_nuevo;
                $vehiculo->save();

                return response()->json([
                    'message' => 'Horómetro actualizado correctamente',
                    'registro' => $registro->load('usuario'),
                    'vehiculo' => $vehiculo
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al registrar horómetro: ' . $e->getMessage()], 500);
        }
    }
}
