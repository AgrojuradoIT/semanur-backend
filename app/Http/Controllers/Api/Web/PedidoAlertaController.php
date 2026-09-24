<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryOrders\PedidoAlertaResource;
use App\Models\PedidoAlerta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Bandeja Web de alertas de pedidos (plan sección 12).
 *
 * Solo la propia bandeja: un alerta ajena responde 404 sin revelar existencia.
 * La capacidad se revalida en cada lectura vía PedidoAlertaPolicy. Esta API no
 * toca el pipeline legacy de Notificacion/FCM.
 */
class PedidoAlertaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PedidoAlerta::class);

        $usuario = $request->user();

        $validated = $request->validate([
            'tipo' => ['nullable', 'string', 'max:80'],
            'leida' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = PedidoAlerta::query()
            ->where('destinatario_user_id', $usuario->id)
            ->with('pedido:pedido_id,uuid,numero,estado')
            ->orderByDesc('alerta_id');

        if (! empty($validated['tipo'])) {
            $query->where('tipo', $validated['tipo']);
        }

        if (array_key_exists('leida', $validated) && $validated['leida'] !== null) {
            $validated['leida']
                ? $query->whereNotNull('leida_at')
                : $query->whereNull('leida_at');
        }

        $paginator = $query->paginate($validated['per_page'] ?? 25);

        $sinLeer = PedidoAlerta::query()
            ->where('destinatario_user_id', $usuario->id)
            ->whereNull('leida_at')
            ->count();

        return response()->json([
            'success' => true,
            'data' => PedidoAlertaResource::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'unread_count' => $sinLeer,
            ],
        ]);
    }

    public function read(Request $request, int $alert): JsonResponse
    {
        $alerta = PedidoAlerta::query()
            ->with('pedido:pedido_id,uuid,numero,estado')
            ->find($alert);

        if ($alerta === null || (int) $alerta->destinatario_user_id !== (int) $request->user()->id) {
            abort(404);
        }

        Gate::authorize('read', $alerta);

        // Idempotente: marcarLeida() no reescribe si ya estaba leída.
        $alerta->marcarLeida();

        return response()->json([
            'success' => true,
            'data' => (new PedidoAlertaResource($alerta))->resolve($request),
        ]);
    }
}
