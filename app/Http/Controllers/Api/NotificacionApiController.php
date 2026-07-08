<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notificacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificacionApiController extends Controller
{
    /**
     * Listar notificaciones del usuario autenticado
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'No autorizado'], 401);
        }

        $query = Notificacion::where('user_id', $user->id);

        // Filtro por no leídas
        if ($request->has('unread_only')) {
            $query->whereNull('fecha_leido');
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $paginated = $query->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $items = $paginated->getCollection()->map(function ($item) {
            $item->leida = !is_null($item->fecha_leido);
            return $item;
        });

        $unreadCount = Notificacion::where('user_id', $user->id)
            ->whereNull('fecha_leido')
            ->count();

        return response()->json([
            'success' => true,
            'data' => $items,
            'total' => $paginated->total(),
            'unread_count' => $unreadCount,
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ]);
    }

    /**
     * Marcar notificación como leída
     */
    public function markAsRead(int $id)
    {
        $user = Auth::user();
        $notificacion = Notificacion::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$notificacion) {
            return response()->json(['error' => 'Notificacion no encontrada'], 404);
        }

        $notificacion->update([
            'fecha_leido' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Notificacion marcada como leida'
        ]);
    }

    /**
     * Marcar todas como leídas
     */
    public function markAllAsRead()
    {
        $user = Auth::user();
        Notificacion::where('user_id', $user->id)
            ->whereNull('fecha_leido')
            ->update(['fecha_leido' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Todas las notificaciones marcadas como leidas'
        ]);
    }

    /**
     * Eliminar notificación
     */
    public function destroy(int $id)
    {
        $user = Auth::user();
        $notificacion = Notificacion::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$notificacion) {
            return response()->json(['error' => 'Notificacion no encontrada'], 404);
        }

        $notificacion->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notificacion eliminada'
        ]);
    }

    /**
     * Eliminar todas las notificaciones leídas
     */
    public function destroyRead()
    {
        $user = Auth::user();
        Notificacion::where('user_id', $user->id)
            ->whereNotNull('fecha_leido')
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notificaciones leídas eliminadas'
        ]);
    }

    /**
     * Sincronizar lectura de notificaciones en batch
     */
    public function syncRead(Request $request)
    {
        $user = Auth::user();
        $ids = $request->input('ids', []);

        if (empty($ids) || !is_array($ids)) {
            return response()->json(['success' => true]);
        }

        Notificacion::where('user_id', $user->id)
            ->whereIn('id', $ids)
            ->whereNull('fecha_leido')
            ->update(['fecha_leido' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Notificaciones sincronizadas'
        ]);
    }

    /**
     * Obtener el conteo de notificaciones no leídas (liviano)
     */
    public function unreadCount()
    {
        $user = Auth::user();
        
        $count = Notificacion::where('user_id', $user->id)
            ->whereNull('fecha_leido')
            ->count();

        return response()->json([
            'success' => true,
            'unread_count' => $count
        ]);
    }
}
