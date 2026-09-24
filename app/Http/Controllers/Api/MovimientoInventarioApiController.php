<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMovimientoInventarioRequest;
use App\Http\Resources\MovimientoInventarioResource;
use App\Models\Bodega;
use App\Models\TransaccionInventario;
use App\Services\NotificacionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MovimientoInventarioApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'producto_id' => ['nullable', 'integer', 'exists:productos,producto_id'],
            'transaccion_tipo' => ['nullable', Rule::in(['entrada', 'ingreso', 'salida', 'transferencia'])],
            'bodega_id' => ['nullable', 'integer', 'exists:bodegas,bodega_id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = TransaccionInventario::query()
            ->with([
                'producto:producto_id,categoria_id,producto_sku,referencia_fabrica,producto_nombre,producto_unidad_medida,producto_stock_actual,producto_alerta_stock_minimo,capacidad_maxima,producto_precio_costo,producto_ubicacion',
                'usuario:id,name',
                'bodega:bodega_id,nombre,tipo',
                'bodegaOrigen:bodega_id,nombre,tipo',
                'bodegaDestino:bodega_id,nombre,tipo',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('transaccion_id');

        $query->when(
            isset($validated['producto_id']),
            fn ($builder) => $builder->where('producto_id', $validated['producto_id'])
        );
        $query->when(
            isset($validated['transaccion_tipo']),
            fn ($builder) => $builder->where('transaccion_tipo', $validated['transaccion_tipo'])
        );
        $query->when(isset($validated['bodega_id']), function ($builder) use ($validated): void {
            $bodegaId = $validated['bodega_id'];
            $builder->where(function ($warehouseQuery) use ($bodegaId): void {
                $warehouseQuery
                    ->where('bodega_id', $bodegaId)
                    ->orWhere('bodega_origen_id', $bodegaId)
                    ->orWhere('bodega_destino_id', $bodegaId);
            });
        });

        $paginator = $query->paginate($validated['per_page'] ?? 25);
        $data = MovimientoInventarioResource::collection($paginator->getCollection())->resolve($request);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreMovimientoInventarioRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $actorId = (int) $request->user()->id;
        $operationHash = $this->operationHash($validated);

        if ($existing = $this->findOperation($actorId, $validated['client_operation_id'])) {
            return $this->replayResponse($request, $existing, $operationHash);
        }

        try {
            $result = DB::transaction(function () use ($validated, $actorId, $operationHash): array {
                if ($existing = $this->findOperation($actorId, $validated['client_operation_id'], true)) {
                    return ['movimiento' => $existing, 'idempotent_replay' => true];
                }

                $this->validateTransferTypes($validated);

                $tipo = $validated['transaccion_tipo'];
                $movimiento = TransaccionInventario::create([
                    'producto_id' => $validated['producto_id'],
                    'bodega_id' => $tipo === 'transferencia' ? null : $validated['bodega_id'],
                    'bodega_origen_id' => $validated['bodega_origen_id'] ?? null,
                    'bodega_destino_id' => $validated['bodega_destino_id'] ?? null,
                    'usuario_id' => $actorId,
                    'client_operation_id' => $validated['client_operation_id'],
                    'client_operation_hash' => $operationHash,
                    'transaccion_tipo' => $tipo,
                    'transaccion_cantidad' => $validated['transaccion_cantidad'],
                    'transaccion_motivo' => $validated['transaccion_motivo'] ?? null,
                    'transaccion_referencia_id' => $validated['transaccion_referencia_id'] ?? null,
                    'transaccion_referencia_type' => $validated['transaccion_referencia_type'] ?? null,
                    'transaccion_notas' => $validated['transaccion_notas'] ?? null,
                ]);

                return ['movimiento' => $movimiento, 'idempotent_replay' => false];
            });
        } catch (QueryException $exception) {
            $existing = $this->findOperation($actorId, $validated['client_operation_id']);
            if (! $existing) {
                throw $exception;
            }

            return $this->replayResponse($request, $existing, $operationHash);
        }

        $movimiento = $this->loadSafeRelations($result['movimiento']);
        if ($result['idempotent_replay']) {
            return $this->replayResponse($request, $movimiento, $operationHash);
        }

        $producto = $movimiento->producto;
        $warning = null;
        if ($movimiento->transaccion_tipo === 'salida'
            && (float) $producto->producto_stock_actual <= (float) $producto->producto_alerta_stock_minimo) {
            $warning = 'Se ha alcanzado o superado el stock mínimo de alerta para este producto.';
            NotificacionService::stockBajo($producto);
        }

        return $this->movementResponse($request, $movimiento, false, $warning, 201);
    }

    private function validateTransferTypes(array $validated): void
    {
        if ($validated['transaccion_tipo'] !== 'transferencia') {
            return;
        }

        $bodegas = Bodega::query()
            ->whereIn('bodega_id', [$validated['bodega_origen_id'], $validated['bodega_destino_id']])
            ->get()
            ->keyBy('bodega_id');
        $origen = $bodegas->get($validated['bodega_origen_id']);
        $destino = $bodegas->get($validated['bodega_destino_id']);

        if (! $origen || ! $destino || $origen->tipo !== 'estandar' || $destino->tipo !== 'recuperacion') {
            throw ValidationException::withMessages([
                'bodega_destino_id' => ['Solo se permite transferencia desde bodega estandar hacia bodega recuperacion.'],
            ]);
        }
    }

    private function findOperation(int $actorId, string $operationId, bool $lock = false): ?TransaccionInventario
    {
        $query = TransaccionInventario::query()
            ->where('usuario_id', $actorId)
            ->where('client_operation_id', $operationId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function replayResponse(
        Request $request,
        TransaccionInventario $movimiento,
        string $operationHash
    ): JsonResponse {
        if (! hash_equals((string) $movimiento->client_operation_hash, $operationHash)) {
            return response()->json([
                'message' => 'client_operation_id ya fue utilizado con un movimiento diferente.',
            ], 409);
        }

        return $this->movementResponse($request, $this->loadSafeRelations($movimiento), true, null, 200);
    }

    private function movementResponse(
        Request $request,
        TransaccionInventario $movimiento,
        bool $replay,
        ?string $warning,
        int $status
    ): JsonResponse {
        return response()->json([
            'message' => $replay ? 'Movimiento ya registrado previamente' : 'Movimiento registrado con exito',
            'movimiento' => (new MovimientoInventarioResource($movimiento))->resolve($request),
            'nuevo_stock_global' => (float) $movimiento->producto->producto_stock_actual,
            'warning' => $warning,
            'idempotent_replay' => $replay,
        ], $status);
    }

    private function loadSafeRelations(TransaccionInventario $movimiento): TransaccionInventario
    {
        return $movimiento->load([
            'producto',
            'usuario:id,name',
            'bodega:bodega_id,nombre,tipo',
            'bodegaOrigen:bodega_id,nombre,tipo',
            'bodegaDestino:bodega_id,nombre,tipo',
        ]);
    }

    private function operationHash(array $payload): string
    {
        $canonical = [
            'producto_id' => (int) $payload['producto_id'],
            'transaccion_tipo' => $payload['transaccion_tipo'],
            'transaccion_cantidad' => number_format((float) $payload['transaccion_cantidad'], 2, '.', ''),
            'transaccion_motivo' => $payload['transaccion_motivo'] ?? null,
            'transaccion_referencia_id' => isset($payload['transaccion_referencia_id']) ? (int) $payload['transaccion_referencia_id'] : null,
            'transaccion_referencia_type' => $payload['transaccion_referencia_type'] ?? null,
            'transaccion_notas' => $payload['transaccion_notas'] ?? null,
            'bodega_id' => isset($payload['bodega_id']) ? (int) $payload['bodega_id'] : null,
            'bodega_origen_id' => isset($payload['bodega_origen_id']) ? (int) $payload['bodega_origen_id'] : null,
            'bodega_destino_id' => isset($payload['bodega_destino_id']) ? (int) $payload['bodega_destino_id'] : null,
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
