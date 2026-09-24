<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrdenTrabajoRequest;
use App\Models\Empleado;
use App\Models\OrdenTrabajo;
use App\Models\PrestamoHerramienta;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use App\Models\User;
use App\Services\Inventory\InventoryWarehouseResolver;
use App\Services\MediaService;
use App\Services\NotificacionService;
use App\Support\ClientOperationHasher;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrdenTrabajoApiController extends Controller
{
    public function __construct(
        private readonly InventoryWarehouseResolver $warehouseResolver,
    ) {}

    private function getEmpleadoIdForUser(int $userId): ?int
    {
        return Empleado::where('user_id', $userId)->value('id');
    }

    private function canAccessOrden(User $user, OrdenTrabajo $orden): bool
    {
        if ($user->isAdmin() || $user->isJefeDeTaller() || $user->isAuxiliarBodega()) {
            return true;
        }

        $assignedId = (int) $orden->mecanico_asignado_id;
        if ($assignedId === 0) {
            return false;
        }

        $userId = (int) $user->id;
        $empleadoId = $this->getEmpleadoIdForUser($userId);

        return $assignedId === $userId || ($empleadoId !== null && $assignedId === $empleadoId);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = OrdenTrabajo::with(['vehiculo', 'mecanico', 'movimientos_inventario.producto', 'sesiones', 'evidencias']);

        if (! $user->isAdmin() && ! $user->isJefeDeTaller() && ! $user->isAuxiliarBodega()) {
            $ids = [(int) $user->id];
            $empleadoId = $this->getEmpleadoIdForUser((int) $user->id);
            if ($empleadoId !== null) {
                $ids[] = $empleadoId;
            }

            $query->whereIn('mecanico_asignado_id', $ids);
        }

        return response()->json($query->orderBy('fecha_inicio', 'desc')->get());
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $orden = OrdenTrabajo::with([
            'vehiculo',
            'mecanico',
            'movimientos_inventario.producto',
            'sesiones.user',
            'sesiones.empleado',
            'evidencias',
        ])->find($id);

        if (! $orden) {
            return response()->json(['message' => 'Orden de trabajo no encontrada'], 404);
        }

        if (! $this->canAccessOrden($user, $orden)) {
            return response()->json(['message' => 'No autorizado para ver esta orden'], 403);
        }

        return response()->json($orden);
    }

    public function store(StoreOrdenTrabajoRequest $request, MediaService $mediaService): JsonResponse
    {
        $validated = $request->validated();
        $actorId = (int) $request->user()->id;
        $operationId = $validated['client_operation_id'] ?? null;

        try {
            $warehouse = $this->warehouseResolver->resolve(
                isset($validated['bodega_id']) ? (int) $validated['bodega_id'] : null,
            );
            $operationHash = $operationId === null
                ? null
                : ClientOperationHasher::hash($this->workOrderOperationPayload(
                    $validated,
                    $warehouse->bodega_id,
                ));

            if ($operationId !== null) {
                $existing = $this->findWorkOrderOperation($actorId, $operationId);
                if ($existing !== null) {
                    return $this->workOrderReplayResponse($existing, $operationHash);
                }
            } else {
                Log::notice('Work-order write received without client_operation_id during compatibility rollout.', [
                    'actor_id' => $actorId,
                ]);
            }

            $orden = DB::transaction(function () use (
                $request,
                $validated,
                $actorId,
                $operationId,
                $operationHash,
                $warehouse,
                $mediaService,
            ): OrdenTrabajo {
                if ($operationId !== null) {
                    $existing = $this->findWorkOrderOperation($actorId, $operationId, true);
                    if ($existing !== null) {
                        if (! hash_equals((string) $existing->client_operation_hash, (string) $operationHash)) {
                            throw ValidationException::withMessages([
                                'client_operation_id' => 'La clave ya fue usada con una orden diferente.',
                            ]);
                        }

                        return $existing;
                    }
                }

                $orden = OrdenTrabajo::create([
                    'created_by_user_id' => $actorId,
                    'client_operation_id' => $operationId,
                    'client_operation_hash' => $operationHash,
                    'vehiculo_id' => (int) $validated['vehiculo_id'],
                    'mecanico_asignado_id' => isset($validated['mecanico_asignado_id'])
                        ? (int) $validated['mecanico_asignado_id']
                        : null,
                    'prioridad' => $validated['prioridad'],
                    'descripcion' => $validated['descripcion'],
                    'estado' => 'Abierta',
                    'fecha_inicio' => now(),
                ]);

                foreach ($validated['repuestos'] ?? [] as $repuesto) {
                    $cantidad = (float) $repuesto['cantidad'];
                    $producto = Producto::lockForUpdate()->find($repuesto['producto_id']);

                    if (! $producto || $producto->producto_stock_actual < $cantidad) {
                        throw ValidationException::withMessages([
                            'repuestos' => ["Stock insuficiente para el producto ID {$repuesto['producto_id']}"],
                        ]);
                    }

                    TransaccionInventario::create([
                        'producto_id' => $repuesto['producto_id'],
                        'bodega_id' => $warehouse->bodega_id,
                        'usuario_id' => $actorId,
                        'transaccion_tipo' => 'salida',
                        'transaccion_cantidad' => $cantidad,
                        'transaccion_motivo' => 'Repuesto para Orden de Trabajo',
                        'transaccion_referencia_id' => $orden->orden_trabajo_id,
                        'transaccion_referencia_type' => 'OrdenTrabajo',
                        'transaccion_notas' => "Repuesto para OT #{$orden->orden_trabajo_id}",
                    ]);
                }

                foreach ($validated['herramientas'] ?? [] as $tool) {
                    $cantidad = isset($tool['cantidad']) ? (float) $tool['cantidad'] : 1.0;
                    $producto = Producto::lockForUpdate()->find($tool['producto_id']);
                    $mecanicoPrestamoId = $orden->mecanico_asignado_id
                        ?: $this->getEmpleadoIdForUser($actorId);

                    if (! $producto || $producto->producto_stock_actual < $cantidad) {
                        throw ValidationException::withMessages([
                            'herramientas' => ["Stock insuficiente para herramienta ID {$tool['producto_id']}"],
                        ]);
                    }

                    if ($mecanicoPrestamoId === null) {
                        throw ValidationException::withMessages([
                            'mecanico_asignado_id' => 'Debe asignar un empleado para prestar herramientas.',
                        ]);
                    }

                    $prestamo = PrestamoHerramienta::create([
                        'producto_id' => $tool['producto_id'],
                        'mecanico_id' => $mecanicoPrestamoId,
                        'admin_id' => $actorId,
                        'prestamo_cantidad' => $cantidad,
                        'fecha_prestamo' => now(),
                        'estado' => 'prestado',
                        'notas' => "Herramienta usada en OT #{$orden->orden_trabajo_id}",
                    ]);

                    TransaccionInventario::create([
                        'producto_id' => $tool['producto_id'],
                        'bodega_id' => $warehouse->bodega_id,
                        'usuario_id' => $actorId,
                        'transaccion_tipo' => 'salida',
                        'transaccion_cantidad' => $cantidad,
                        'transaccion_motivo' => 'Prestamo de herramienta para OT',
                        'transaccion_referencia_id' => $prestamo->prestamo_id,
                        'transaccion_referencia_type' => 'PrestamoHerramienta',
                        'transaccion_notas' => "Prestamo #{$prestamo->prestamo_id} asociado a OT #{$orden->orden_trabajo_id}",
                    ]);
                }

                if ($request->hasFile('foto_evidencia')) {
                    $media = $mediaService->storeUploadedFile(
                        $request->file('foto_evidencia'),
                        module: 'taller',
                        entityType: 'orden_trabajo',
                        entityId: $orden->orden_trabajo_id,
                        userId: $actorId,
                        notas: $validated['notas_foto_evidencia'] ?? null,
                    );

                    $orden->update(['foto_evidencia' => $media->path]);
                }

                return $orden;
            });

            $idempotentReplay = $operationId !== null && ! $orden->wasRecentlyCreated;
            if (! $idempotentReplay) {
                try {
                    NotificacionService::ordenCreada($orden);
                } catch (Throwable $notificationError) {
                    Log::warning('Work-order notification failed after a successful commit.', [
                        'orden_trabajo_id' => $orden->orden_trabajo_id,
                        'exception' => $notificationError,
                    ]);
                }
            }

            return response()->json([
                'message' => $idempotentReplay
                    ? 'La orden ya había sido registrada.'
                    : 'Orden de trabajo creada correctamente con items asociados',
                'orden' => $orden->load(['vehiculo', 'movimientos_inventario.producto']),
                'idempotent_replay' => $idempotentReplay,
                'idempotency_protected' => $operationId !== null,
            ], $idempotentReplay ? 200 : 201);
        } catch (ValidationException $exception) {
            if ($operationId !== null && isset($operationHash)) {
                $existing = $this->findWorkOrderOperation($actorId, $operationId);
                if ($existing !== null) {
                    return $this->workOrderReplayResponse($existing, $operationHash);
                }
            }

            throw $exception;
        } catch (QueryException $exception) {
            if ($operationId !== null && isset($operationHash)) {
                $existing = $this->findWorkOrderOperation($actorId, $operationId);
                if ($existing !== null) {
                    return $this->workOrderReplayResponse($existing, $operationHash);
                }
            }

            return $this->workOrderServerError($exception, $actorId, $operationId);
        } catch (Throwable $exception) {
            return $this->workOrderServerError($exception, $actorId, $operationId);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'estado' => 'required|in:Abierta,En Progreso,Pendiente Auditoria,Aprobada,Cerrada',
            'notas_auditoria' => 'nullable|string',
        ]);

        $user = $request->user();
        $orden = OrdenTrabajo::find($id);

        if (! $orden) {
            return response()->json(['message' => 'Orden de trabajo no encontrada'], 404);
        }

        if (! $this->canAccessOrden($user, $orden)) {
            return response()->json(['message' => 'No autorizado para actualizar esta orden'], 403);
        }

        // Si se intenta mover a Aprobada/Cerrada, o devolver desde Pendiente Auditoria a En Progreso, solo jefe de taller o admin
        $isAuditAction = in_array($request->estado, ['Aprobada', 'Cerrada']) ||
                         ($orden->estado === 'Pendiente Auditoria' && $request->estado === 'En Progreso');

        if ($isAuditAction && ! $user->isAdmin() && ! $user->isJefeDeTaller()) {
            return response()->json(['message' => 'Solo el jefe de taller o el administrador pueden auditar y aprobar/rechazar órdenes de trabajo'], 403);
        }

        $estadoAnterior = $orden->estado;
        $orden->estado = $request->estado;

        if ($request->estado === 'Pendiente Auditoria' || $request->estado === 'Cerrada' || $request->estado === 'Aprobada') {
            $orden->fecha_fin = $orden->fecha_fin ?? now();
        }

        if ($request->has('notas_auditoria')) {
            $orden->notas_auditoria = $request->notas_auditoria;
        }

        $orden->save();

        NotificacionService::ordenEstadoCambiado($orden, $estadoAnterior);

        return response()->json([
            'message' => 'Estado actualizado correctamente',
            'orden' => $orden,
        ]);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'mecanico_asignado_id' => 'nullable|integer',
            'prioridad' => 'nullable|in:Alta,Media,Baja',
            'descripcion' => 'nullable|string',
        ]);

        $user = $request->user();

        if (! $user->isAdmin() && ! $user->isJefeDeTaller()) {
            return response()->json(['message' => 'No autorizado para editar esta orden'], 403);
        }

        $orden = OrdenTrabajo::find($id);

        if (! $orden) {
            return response()->json(['message' => 'Orden de trabajo no encontrada'], 404);
        }

        $mecanicoCambio = false;
        $mecanicoAnteriorId = $orden->mecanico_asignado_id;

        if ($request->has('mecanico_asignado_id')) {
            $mecanicoAsignadoId = null;
            if ($request->mecanico_asignado_id) {
                $inputId = (int) $request->mecanico_asignado_id;
                $empleado = Empleado::find($inputId);
                if ($empleado) {
                    $mecanicoAsignadoId = $empleado->id;
                }
            }
            if ($orden->mecanico_asignado_id !== $mecanicoAsignadoId) {
                $mecanicoCambio = true;
            }
            $orden->mecanico_asignado_id = $mecanicoAsignadoId;
        }

        if ($request->has('prioridad')) {
            $orden->prioridad = $request->prioridad;
        }

        if ($request->has('descripcion')) {
            $orden->descripcion = $request->descripcion;
        }

        $orden->save();

        if ($mecanicoCambio) {
            NotificacionService::ordenReasignada($orden, $mecanicoAnteriorId);
        }

        return response()->json([
            'message' => 'Orden de trabajo actualizada correctamente',
            'orden' => $orden->load(['vehiculo', 'mecanico']),
        ]);
    }

    public function addRepuestos(Request $request, $id)
    {
        $request->validate([
            'repuestos' => 'required|array',
            'repuestos.*.producto_id' => 'required|exists:productos,producto_id',
            'repuestos.*.cantidad' => 'required|numeric|min:1',
        ]);

        $user = $request->user();
        // The route middleware handles role checking, but let's be double safe if we want.

        $orden = OrdenTrabajo::find($id);

        if (! $orden) {
            return response()->json(['message' => 'Orden de trabajo no encontrada'], 404);
        }

        if (in_array($orden->estado, ['Cerrada'])) {
            return response()->json(['message' => 'No se pueden añadir repuestos a una orden cerrada'], 400);
        }

        try {
            DB::beginTransaction();

            $bodegaId = $this->resolveDefaultBodegaId();

            foreach ($request->repuestos as $repuesto) {
                $cantidad = (float) $repuesto['cantidad'];
                $producto = Producto::lockForUpdate()->find($repuesto['producto_id']);

                if (! $producto || $producto->producto_stock_actual < $cantidad) {
                    throw ValidationException::withMessages([
                        'repuestos' => ["Stock insuficiente para: {$producto->producto_nombre}"],
                    ]);
                }

                TransaccionInventario::create([
                    'producto_id' => $repuesto['producto_id'],
                    'bodega_id' => $bodegaId,
                    'usuario_id' => $user->id,
                    'transaccion_tipo' => 'salida',
                    'transaccion_cantidad' => $cantidad,
                    'transaccion_motivo' => 'Repuesto adicional para OT',
                    'transaccion_referencia_id' => $orden->orden_trabajo_id,
                    'transaccion_referencia_type' => 'OrdenTrabajo',
                    'transaccion_notas' => "Repuesto adicionado a OT #{$orden->orden_trabajo_id}",
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Repuestos añadidos correctamente',
                'orden' => $orden->load(['vehiculo', 'mecanico', 'movimientos_inventario.producto', 'sesiones.empleado']),
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error de validación de stock',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->workOrderServerError($e, (int) $user->id, null);
        }
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            return response()->json(['message' => 'No autorizado para eliminar órdenes de trabajo'], 403);
        }

        return DB::transaction(function () use ($id, $user) {
            $orden = OrdenTrabajo::find($id);

            if (! $orden) {
                return response()->json(['message' => 'Orden de trabajo no encontrada'], 404);
            }

            if (in_array($orden->estado, ['Abierta', 'En Progreso'])) {
                return response()->json([
                    'message' => 'No se puede eliminar: la orden de trabajo está activa',
                ], 409);
            }

            // Revertir salidas de inventario asociadas mediante contraasientos
            $bodegaId = $this->resolveDefaultBodegaId();
            $movimientos = TransaccionInventario::where('transaccion_referencia_type', 'OrdenTrabajo')
                ->where('transaccion_referencia_id', $orden->orden_trabajo_id)
                ->where('transaccion_tipo', 'salida')
                ->get();

            foreach ($movimientos as $mov) {
                TransaccionInventario::create([
                    'reverses_transaction_id' => $mov->transaccion_id,
                    'producto_id' => $mov->producto_id,
                    'bodega_id' => $mov->bodega_id ?? $bodegaId,
                    'usuario_id' => $user->id,
                    'transaccion_tipo' => 'ingreso',
                    'transaccion_cantidad' => $mov->transaccion_cantidad,
                    'transaccion_motivo' => 'Reversión por eliminación de OT',
                    'transaccion_referencia_id' => $orden->orden_trabajo_id,
                    'transaccion_referencia_type' => 'OrdenTrabajo',
                    'transaccion_notas' => "Reintegro de repuesto por eliminación de OT #{$orden->orden_trabajo_id}",
                ]);
            }

            $orden->sesiones()->delete();
            $orden->delete();

            return response()->json(['message' => 'Orden de trabajo eliminada correctamente']);
        });
    }

    private function resolveDefaultBodegaId(): int
    {
        return (int) $this->warehouseResolver->resolve()->bodega_id;
    }

    private function findWorkOrderOperation(int $actorId, string $operationId, bool $lock = false): ?OrdenTrabajo
    {
        $query = OrdenTrabajo::query()
            ->where('created_by_user_id', $actorId)
            ->where('client_operation_id', $operationId);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function workOrderReplayResponse(OrdenTrabajo $order, string $operationHash): JsonResponse
    {
        if (! hash_equals((string) $order->client_operation_hash, $operationHash)) {
            return response()->json([
                'message' => 'client_operation_id ya fue utilizado con una orden diferente.',
            ], 409);
        }

        return response()->json([
            'message' => 'La orden ya había sido registrada.',
            'orden' => $order->load(['vehiculo', 'movimientos_inventario.producto']),
            'idempotent_replay' => true,
            'idempotency_protected' => true,
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function workOrderOperationPayload(array $validated, int $warehouseId): array
    {
        $payload = [
            'bodega_id' => $warehouseId,
            'vehiculo_id' => (int) $validated['vehiculo_id'],
            'mecanico_asignado_id' => isset($validated['mecanico_asignado_id'])
                ? (int) $validated['mecanico_asignado_id']
                : null,
            'prioridad' => $validated['prioridad'],
            'descripcion' => $validated['descripcion'],
            'repuestos' => $validated['repuestos'] ?? [],
            'herramientas' => $validated['herramientas'] ?? [],
            'foto_evidencia' => $validated['foto_evidencia'] ?? null,
            'notas_foto_evidencia' => $validated['notas_foto_evidencia'] ?? null,
        ];

        foreach (['repuestos', 'herramientas'] as $linesKey) {
            $payload[$linesKey] = array_map(static fn (array $line): array => [
                'producto_id' => (int) $line['producto_id'],
                'cantidad' => (float) ($line['cantidad'] ?? 1),
            ], $payload[$linesKey]);

            usort($payload[$linesKey], static function (array $left, array $right): int {
                return [$left['producto_id'], $left['cantidad']] <=> [$right['producto_id'], $right['cantidad']];
            });
        }

        return $payload;
    }

    private function workOrderServerError(
        Throwable $exception,
        int $actorId,
        ?string $operationId,
    ): JsonResponse {
        $errorId = (string) Str::uuid();
        Log::error('Work-order operation failed.', [
            'error_id' => $errorId,
            'actor_id' => $actorId,
            'client_operation_id' => $operationId,
            'exception' => $exception,
        ]);

        return response()->json([
            'message' => 'Error interno al procesar la orden de trabajo.',
            'error_id' => $errorId,
        ], 500);
    }
}
