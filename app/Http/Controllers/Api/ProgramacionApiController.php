<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Programacion;
use App\Models\OrdenTrabajo;
use App\Models\Novedad;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\NotificacionService;

class ProgramacionApiController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date',
        ]);

        $programacion = Programacion::query()
            ->with([
                'empleado:id,nombres,apellidos,cargo',
                'vehiculo:vehiculo_id,placa,tipo,marca,modelo',
                'ordenTrabajo:orden_trabajo_id,vehiculo_id,estado,prioridad,fecha_inicio,fecha_fin,descripcion',
            ])
            ->whereBetween('fecha', [$request->fecha_inicio, $request->fecha_fin])
            ->orderBy('fecha', 'asc')
            ->get();

        return response()->json($programacion);
    }

    public function store(Request $request)
    {
        $request->validate([
            'fecha' => 'required|date',
            'empleado_id' => 'required|exists:empleados,id',
            'vehiculo_id' => 'nullable|exists:vehiculos,vehiculo_id|required_if:crear_orden_trabajo,true',
            'labor' => 'required|string',
            'ubicacion' => 'nullable|string',
            'crear_orden_trabajo' => 'boolean' // Opcional, si se quiere forzar OT
        ], [
            'vehiculo_id.required_if' => 'Debe seleccionar un vehículo si la opción "Crear orden de trabajo automáticamente" está activa.',
        ]);

        $existe = Programacion::where('empleado_id', $request->empleado_id)
            ->where('fecha', $request->fecha)
            ->exists();

        if ($existe) {
            return response()->json([
                'message' => 'El empleado ya tiene una labor programada para esta misma fecha y hora.'
            ], 422);
        }

        return DB::transaction(function () use ($request) {
            $programacion = Programacion::create([
                'fecha' => $request->fecha,
                'empleado_id' => $request->empleado_id,
                'vehiculo_id' => $request->vehiculo_id,
                'labor' => $request->labor,
                'ubicacion' => $request->ubicacion,
                'estado' => 'pendiente',
            ]);

            // Si se requiere OT automática (lógica de negocio o flag)
            if ($request->crear_orden_trabajo) {
                // Crear OT básica
                $ot = OrdenTrabajo::create([
                    'vehiculo_id' => $request->vehiculo_id,
                    'mecanico_asignado_id' => $request->empleado_id,
                    'fecha_inicio' => $request->fecha,
                    'estado' => 'Abierta',
                    'prioridad' => 'Media',
                    'descripcion' => "Programación: " . $request->labor,
                ]);
                
                $programacion->orden_trabajo_id = $ot->orden_trabajo_id;
                $programacion->save();
            }

            NotificacionService::programacionAsignada($programacion);

            return response()->json($programacion, 201);
        });
    }

    public function novedad(Request $request, MediaService $mediaService)
    {
        $request->validate([
            'fecha' => 'required|date',
            'empleado_id' => 'required|exists:empleados,id',
            'vehiculo_id' => 'nullable|exists:vehiculos,vehiculo_id',
            'descripcion' => 'required|string',
            'prioridad' => 'nullable|string',
            'pausar_actividad' => 'nullable|boolean',
            'foto' => 'nullable|image|max:5120',
        ]);

        return DB::transaction(function () use ($request, $mediaService) {
            // 1. Pausar programación actual si se solicita
            if ($request->boolean('pausar_actividad')) {
                Programacion::where('empleado_id', $request->empleado_id)
                    ->where('fecha', $request->fecha)
                    ->where('estado', 'pendiente')
                    ->update(['estado' => 'pausado']);
            }

            // 2. Registrar la Novedad en la tabla dedicada
            $novedad = Novedad::create([
                'fecha' => $request->fecha,
                'empleado_id' => $request->empleado_id,
                'vehiculo_id' => $request->vehiculo_id,
                'descripcion' => $request->descripcion,
                'prioridad' => $request->prioridad ?? 'Normal',
                'pausar_actividad' => $request->boolean('pausar_actividad'),
            ]);

            // 3. Crear Orden de Trabajo si hay vehículo
            $ot = null;
            if ($request->vehiculo_id) {
                $ot = OrdenTrabajo::create([
                    'vehiculo_id' => $request->vehiculo_id,
                    'mecanico_asignado_id' => $request->empleado_id,
                    'fecha_inicio' => Carbon::now(),
                    'estado' => 'Abierta',
                    'prioridad' => (strtoupper($request->prioridad ?? '') === 'URGENTE') ? 'Alta' : 'Media',
                    'descripcion' => "NOVEDAD (" . ($request->prioridad ?? 'NORMAL') . "): " . $request->descripcion,
                ]);

                $novedad->orden_trabajo_id = $ot->orden_trabajo_id;
                $novedad->save();
            }

            // 4. Si viene una foto, guardarla asociada a la novedad
            $media = null;
            if ($request->hasFile('foto')) {
                $media = $mediaService->storeUploadedFile(
                    $request->file('foto'),
                    module: 'programacion',
                    entityType: 'novedad',
                    entityId: $novedad->id,
                    userId: $request->user()?->id
                );
            }

            NotificacionService::novedadReportada($novedad);

            return response()->json([
                'message' => 'Novedad registrada exitosamente.',
                'novedad' => $novedad,
                'ot' => $ot,
                'media' => $media,
            ], 201);
        });
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'fecha' => 'required|date',
            'empleado_id' => 'required|exists:empleados,id',
            'vehiculo_id' => 'nullable|exists:vehiculos,vehiculo_id',
            'labor' => 'required|string',
            'ubicacion' => 'nullable|string',
        ]);

        $existe = Programacion::where('empleado_id', $request->empleado_id)
            ->where('fecha', $request->fecha)
            ->where('id', '!=', $id)
            ->exists();

        if ($existe) {
            return response()->json([
                'message' => 'El empleado ya tiene una labor programada para esta misma fecha y hora.'
            ], 422);
        }

        $programacion = Programacion::findOrFail($id);

        $programacion->update([
            'fecha' => $request->fecha,
            'empleado_id' => $request->empleado_id,
            'vehiculo_id' => $request->vehiculo_id,
            'labor' => $request->labor,
            'ubicacion' => $request->ubicacion,
        ]);

        return response()->json($programacion->load(['empleado', 'vehiculo', 'ordenTrabajo']));
    }

    public function destroy($id)
    {
        $programacion = Programacion::findOrFail($id);
        $programacion->delete();

        return response()->json(['message' => 'Programación eliminada correctamente.']);
    }
}
