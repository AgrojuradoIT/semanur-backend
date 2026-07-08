<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\ListaChequeo;
use App\Models\RespuestaListaChequeo;
use App\Models\Vehiculo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ChecklistApiController extends Controller
{
    // Removed resolveOperadorUserId as we now strictly use empleado_id in payloads

    // Obtener listas de chequeo activas con sus items
    public function index(Request $request)
    {
        $query = ListaChequeo::with('items')->where('activo', true);

        if ($request->has('tipo_vehiculo') && $request->tipo_vehiculo !== '') {
            $tipo = strtolower(trim($request->tipo_vehiculo));
            $query->where(function ($q) use ($tipo) {
                $q->whereRaw('LOWER(tipo_vehiculo) = ?', [$tipo])
                  ->orWhereRaw('LOWER(tipo_vehiculo) LIKE ?', ["%{$tipo}%"]);
            });
        }

        $listas = $query->get();

        if ($listas->isEmpty() && $request->filled('tipo_vehiculo')) {
            $listas = ListaChequeo::with('items')
                ->where('activo', true)
                ->where(function ($q) {
                    $q->whereNull('tipo_vehiculo')
                      ->orWhere('tipo_vehiculo', '')
                      ->orWhere('tipo_vehiculo', 'generico');
                })
                ->get();
        }

        if ($listas->isEmpty()) {
            $listas = ListaChequeo::with('items')
                ->where('activo', true)
                ->limit(1)
                ->get();
        }

        return response()->json($listas);
    }

    // Guardar una respuesta de lista de chequeo (preoperacional)
    public function store(Request $request)
    {
        // 1. Detectar si es el formato de la App Flutter
        $isFlutterApp = $request->has('checklist_data') || $request->has('empleado_id');

        if ($isFlutterApp) {
            $request->validate([
                'vehiculo_id' => 'required|exists:vehiculos,vehiculo_id',
                'empleado_id' => 'required', // Puede ser user_id o empleado_id
                'checklist_data' => 'required',
                'estado' => 'required|string',
                'observaciones' => 'nullable|string',
            ]);
        } else {
            $request->validate([
                'lista_chequeo_id' => 'required|exists:listas_chequeo,id',
                'vehiculo_id' => 'required|exists:vehiculos,vehiculo_id',
                'operador_id' => 'required', // Puede ser user_id o empleado_id
                'respuestas' => 'required|array', // { item_id: valor }
                'observaciones_generales' => 'nullable|string',
            ]);
        }

        return DB::transaction(function () use ($request, $isFlutterApp) {
            if ($isFlutterApp) {
                // Convertir user_id a empleado_id si es necesario
                $empleadoId = (int) $request->empleado_id;
                $empleado = Empleado::find($empleadoId);
                
                // Si no existe, buscar por user_id
                if (!$empleado) {
                    $empleado = Empleado::where('user_id', $empleadoId)->first();
                }
                
                // Si aún no existe, intentar buscar por el usuario autenticado
                if (!$empleado && $request->user()) {
                    $empleado = Empleado::where('user_id', $request->user()->id)->first();
                }
                
                if (!$empleado) {
                    return response()->json([
                        'message' => 'Empleado no encontrado. Verifique que el usuario tenga un empleado asociado.',
                        'empleado_id_provided' => $empleadoId,
                    ], 422);
                }
                
                $operadorId = $empleado->id;
                $estado = strtolower($request->estado);

                $respuestas = $request->checklist_data;
                if (is_string($respuestas)) {
                    $respuestas = json_decode($respuestas, true);
                }

                // Intentar asociar a una plantilla genérica para evitar foreign key constraint
                // Si la BD requiere lista_chequeo_id, buscamos la primera activa
                $lista = ListaChequeo::where('activo', true)->first();
                $listaId = $lista ? $lista->id : null;

                $respuesta = RespuestaListaChequeo::create([
                    'lista_chequeo_id' => $listaId,
                    'vehiculo_id' => $request->vehiculo_id,
                    'operador_id' => $operadorId,
                    'fecha' => $request->fecha ? Carbon::parse($request->fecha) : Carbon::now(),
                    'respuestas' => $respuestas,
                    'estado' => $estado,
                    'observaciones_generales' => $request->observaciones,
                ]);

                // Update vehiculo horometro if provided
                if ($request->has('horometro_actual') && $request->horometro_actual !== null) {
                    $vehiculo = Vehiculo::find($request->vehiculo_id);
                    if ($vehiculo && $request->horometro_actual > $vehiculo->horometro_actual) {
                        $vehiculo->horometro_actual = $request->horometro_actual;
                        $vehiculo->save();
                    }
                }

            } else {
                // Legacy / Web format
                $operadorId = (int) $request->operador_id;
                
                // Convertir user_id a empleado_id si es necesario
                $empleado = Empleado::find($operadorId);
                if (!$empleado) {
                    $empleado = Empleado::where('user_id', $operadorId)->first();
                }
                if ($empleado) {
                    $operadorId = $empleado->id;
                }
                
                $lista = ListaChequeo::with('items')->find($request->lista_chequeo_id);
                $estado = 'aprobado';

                // Validar respuestas críticas
                if ($lista) {
                    foreach ($lista->items as $item) {
                        if ($item->es_critico && isset($request->respuestas[$item->id])) {
                            $respuestaItem = $request->respuestas[$item->id];
                            if ($respuestaItem === 'falla' || $respuestaItem === false || $respuestaItem === 0) {
                                $estado = 'rechazado';
                            }
                        }
                    }
                }

                $respuesta = RespuestaListaChequeo::create([
                    'lista_chequeo_id' => $request->lista_chequeo_id,
                    'vehiculo_id' => $request->vehiculo_id,
                    'operador_id' => $operadorId,
                    'fecha' => Carbon::now(),
                    'respuestas' => $request->respuestas,
                    'estado' => $estado,
                    'observaciones_generales' => $request->observaciones_generales,
                ]);
            }

            return response()->json([
                'message' => 'Lista de chequeo guardada exitosamente',
                'data' => $respuesta,
                'estado_final' => $estado
            ], 201);
        });
    }

    // Obtener historial de respuestas (opcional, para consultas)
    public function history(Request $request) {
         $query = RespuestaListaChequeo::with(['listaChequeo', 'vehiculo', 'operador'])
                    ->orderBy('fecha', 'desc');

         if ($request->has('vehiculo_id')) {
             $query->where('vehiculo_id', $request->vehiculo_id);
         }
         
         return response()->json($query->paginate(20));
    }
}
