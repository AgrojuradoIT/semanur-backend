<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\ListaChequeo;
use App\Models\RespuestaListaChequeo;
use App\Models\Vehiculo;
use App\Services\Authorization\OperationalAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChecklistApiController extends Controller
{
    public function __construct(
        private readonly OperationalAccess $access,
    ) {}

    // Obtener listas de chequeo activas con sus items
    public function index(Request $request)
    {
        $request->validate([
            'tipo_vehiculo' => ['nullable', 'string', 'max:50'],
        ]);

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
                'vehiculo_id' => 'required|integer|exists:vehiculos,vehiculo_id',
                'empleado_id' => 'required|integer|min:1', // Puede ser user_id o empleado_id
                'checklist_data' => 'required',
                'estado' => 'required|string|in:aprobado,rechazado,pendiente',
                'observaciones' => 'nullable|string|max:5000',
                'fecha' => 'nullable|date',
                'horometro_actual' => 'nullable|numeric|min:0|max:9999999999.99',
            ]);
        } else {
            $request->validate([
                'lista_chequeo_id' => 'required|integer|exists:listas_chequeo,id',
                'vehiculo_id' => 'required|integer|exists:vehiculos,vehiculo_id',
                'operador_id' => 'required|integer|min:1', // Puede ser user_id o empleado_id
                'respuestas' => 'required|array|min:1|max:500', // { item_id: valor }
                'observaciones_generales' => 'nullable|string|max:5000',
            ]);
        }

        return DB::transaction(function () use ($request, $isFlutterApp) {
            if ($isFlutterApp) {
                // Convertir user_id a empleado_id si es necesario
                $empleadoId = (int) $request->empleado_id;
                $empleado = $this->resolveSubmittedEmployee($request, $empleadoId);

                if (! $empleado) {
                    return response()->json([
                        'message' => 'Empleado no encontrado. Verifique que el usuario tenga un empleado asociado.',
                        'empleado_id_provided' => $empleadoId,
                    ], 422);
                }

                $this->authorizeSubmission($request, $empleado, (int) $request->vehiculo_id);

                $operadorId = $empleado->id;
                $estado = strtolower($request->estado);

                $respuestas = $request->checklist_data;
                if (is_string($respuestas)) {
                    $respuestas = json_decode($respuestas, true);
                }

                if (! is_array($respuestas) || $respuestas === [] || count($respuestas) > 500) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'checklist_data' => ['El checklist debe contener entre 1 y 500 respuestas válidas.'],
                    ]);
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
                $empleado = $this->resolveSubmittedEmployee($request, $operadorId);
                if (! $empleado) {
                    return response()->json(['message' => 'Empleado no encontrado.'], 422);
                }

                $this->authorizeSubmission($request, $empleado, (int) $request->vehiculo_id);
                $operadorId = $empleado->id;

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
                'estado_final' => $estado,
            ], 201);
        });
    }

    // Obtener historial de respuestas (opcional, para consultas)
    public function history(Request $request)
    {
        $validated = $request->validate([
            'vehiculo_id' => ['nullable', 'integer', 'exists:vehiculos,vehiculo_id'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = RespuestaListaChequeo::with(['listaChequeo', 'vehiculo', 'operador'])
            ->orderBy('fecha', 'desc');

        if (! $this->access->isSupervisor($request->user())) {
            $employeeId = $this->access->employeeId($request->user());
            $employeeId === null
                ? $query->whereRaw('1 = 0')
                : $query->where('operador_id', $employeeId);
        }

        if (isset($validated['vehiculo_id'])) {
            $query->where('vehiculo_id', $validated['vehiculo_id']);
        }

        return response()->json($query->paginate(20));
    }

    private function resolveSubmittedEmployee(Request $request, int $submittedId): ?Empleado
    {
        if (! $this->access->isSupervisor($request->user())) {
            $actorEmployee = Empleado::query()
                ->where('user_id', $request->user()->getKey())
                ->first();

            if ($actorEmployee !== null
                && in_array($submittedId, [(int) $actorEmployee->getKey(), (int) $request->user()->getKey()], true)) {
                return $actorEmployee;
            }
        }

        return Empleado::query()->find($submittedId)
            ?? Empleado::query()->where('user_id', $submittedId)->first();
    }

    private function authorizeSubmission(Request $request, Empleado $employee, int $vehicleId): void
    {
        if ($this->access->isSupervisor($request->user())) {
            return;
        }

        $actorEmployeeId = $this->access->employeeId($request->user());
        $vehicle = Vehiculo::query()->findOrFail($vehicleId);

        abort_unless(
            $actorEmployeeId !== null
                && $actorEmployeeId === (int) $employee->getKey()
                && $this->access->canUseVehicleForChecklist($request->user(), $vehicle),
            403,
            'No autorizado para registrar el checklist de otro empleado o vehículo.',
        );
    }
}
