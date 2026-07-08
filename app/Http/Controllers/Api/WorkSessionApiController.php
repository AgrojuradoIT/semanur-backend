<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\OrdenTrabajo;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Http\Request;

class WorkSessionApiController extends Controller
{
    private function canAccessOrden($user, OrdenTrabajo $orden): bool
    {
        if ($user->isAdmin() || $user->isJefeDeTaller() || $user->isAuxiliarBodega()) {
            return true;
        }

        $assignedId = (int) $orden->mecanico_asignado_id;
        if ($assignedId === 0) {
            return false;
        }

        $empleadoId = Empleado::where('user_id', $user->id)->value('id');

        return $empleadoId !== null && $assignedId === (int) $empleadoId;
    }

    public function start(Request $request)
    {
        $request->validate([
            'orden_trabajo_id' => 'required|exists:orden_trabajos,orden_trabajo_id',
        ]);

        $user = $request->user();
        $orden = OrdenTrabajo::findOrFail($request->orden_trabajo_id);

        $empleadoId = null;

        if ($user->isAdmin() || $user->isJefeDeTaller() || $user->isAuxiliarBodega()) {
            $empleadoId = $orden->mecanico_asignado_id;
            if (!$empleadoId) {
                return response()->json(['message' => 'La orden no tiene un mecanico asignado para iniciar sesion'], 400);
            }
        } else {
            $empleadoId = Empleado::where('user_id', $user->id)->value('id');
            if (!$empleadoId) {
                return response()->json(['message' => 'El usuario autenticado no tiene un perfil de empleado asociado'], 403);
            }
            if (!$this->canAccessOrden($user, $orden)) {
                return response()->json(['message' => 'No autorizado para iniciar sesion en esta orden'], 403);
            }
        }

        $mecanicoUser = Empleado::find($empleadoId)?->user;
        $isMecanicoAuxiliar = $mecanicoUser && $mecanicoUser->isAuxiliarBodega();

        if (!$isMecanicoAuxiliar) {
            $activeSession = WorkSession::where('empleado_id', $empleadoId)
                ->whereNull('fecha_fin')
                ->first();

            if ($activeSession) {
                return response()->json([
                    'message' => 'Ya existe una sesion activa en la orden #' . $activeSession->orden_trabajo_id . ' para este mecanico.',
                    'session' => $activeSession,
                ], 400);
            }
        }

        $session = WorkSession::create([
            'empleado_id' => $empleadoId,
            'orden_trabajo_id' => $request->orden_trabajo_id,
            'fecha_inicio' => now(),
        ]);

        return response()->json([
            'message' => 'Sesion iniciada correctamente',
            'session' => $session,
        ], 201);
    }

    public function stop(Request $request, $id)
    {
        $user = $request->user();
        $empleadoId = Empleado::where('user_id', $user->id)->value('id');

        $session = WorkSession::findOrFail($id);

        if (
            !$user->isAdmin() && !$user->isJefeDeTaller() && !$user->isAuxiliarBodega()
            && (!$empleadoId || (int) $session->empleado_id !== (int) $empleadoId)
        ) {
            return response()->json(['message' => 'No autorizado para finalizar esta sesion'], 403);
        }

        if ($session->fecha_fin) {
            return response()->json(['message' => 'La sesion ya esta finalizada'], 400);
        }

        $session->update([
            'fecha_fin' => now(),
            'notas' => $request->notas,
        ]);

        return response()->json([
            'message' => 'Sesion finalizada correctamente',
            'session' => $session,
        ]);
    }

    public function activeSession(Request $request)
    {
        $empleadoId = Empleado::where('user_id', $request->user()->id)->value('id');

        if (!$empleadoId) {
            return response()->json(null);
        }

        $session = WorkSession::where('empleado_id', $empleadoId)
            ->whereNull('fecha_fin')
            ->with('ordenTrabajo.vehiculo')
            ->first();

        return response()->json($session);
    }
}
