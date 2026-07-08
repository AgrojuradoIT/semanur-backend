<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bodega;
use App\Models\Empleado;
use App\Models\OrdenTrabajo;
use App\Models\PrestamoHerramienta;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use App\Models\User;
use App\Services\MediaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\NotificacionService;

class OrdenTrabajoApiController extends Controller
{
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

        if (!$user->isAdmin() && !$user->isJefeDeTaller() && !$user->isAuxiliarBodega()) {
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
            'evidencias'
        ])->find($id);

        if (!$orden) {
            return response()->json(['message' => 'Orden de trabajo no encontrada'], 404);
        }

        if (!$this->canAccessOrden($user, $orden)) {
            return response()->json(['message' => 'No autorizado para ver esta orden'], 403);
        }

        return response()->json($orden);
    }

    public function store(Request $request, MediaService $mediaService)
    {
        $request->validate([
            'vehiculo_id' => 'required|exists:vehiculos,vehiculo_id',
            'mecanico_asignado_id' => 'nullable|integer',
            'prioridad' => 'required|in:Alta,Media,Baja',
            'descripcion' => 'required|string',
            'repuestos' => 'nullable|array',
            'repuestos.*.producto_id' => 'required_with:repuestos|exists:productos,producto_id',
            'repuestos.*.cantidad' => 'required_with:repuestos|numeric|min:1',
            'herramientas' => 'nullable|array',
            'herramientas.*.producto_id' => 'required_with:herramientas|exists:productos,producto_id',
            'herramientas.*.cantidad' => 'nullable|numeric|min:1',
            'foto_evidencia' => 'nullable|image|max:5120',
        ]);

        try {
            DB::beginTransaction();
// Resuelve mecanico: el frontend puede enviar empleado_id o user_id
            // Fase 1: mecanicos no tienen usuario → se guarda null
            // Fase 2: cuando tengan cuenta → se usa su user_id automáticamente
            $mecanicoAsignadoId = null;
            if ($request->mecanico_asignado_id) {
                $inputId = (int) $request->mecanico_asignado_id;
                $empleado = Empleado::find($inputId);
                if ($empleado) {
                    $mecanicoAsignadoId = $empleado->id;
                }
            }

            $orden = new OrdenTrabajo();
            $orden->vehiculo_id = $request->vehiculo_id;
            $orden->mecanico_asignado_id = $mecanicoAsignadoId;
            $orden->prioridad = $request->prioridad;
            $orden->descripcion = $request->descripcion;
            $orden->estado = 'Abierta';
            $orden->fecha_inicio = now();
            $orden->save();

            $bodegaId = $this->resolveDefaultBodegaId();

            if ($request->has('repuestos')) {
                foreach ($request->repuestos as $repuesto) {
                    $cantidad = (float) $repuesto['cantidad'];
                    $producto = Producto::lockForUpdate()->find($repuesto['producto_id']);

                    if (!$producto || $producto->producto_stock_actual < $cantidad) {
                        throw ValidationException::withMessages([
                            'repuestos' => ["Stock insuficiente para el producto ID {$repuesto['producto_id']}"],
                        ]);
                    }

                    TransaccionInventario::create([
                        'producto_id' => $repuesto['producto_id'],
                        'bodega_id' => $bodegaId,
                        'usuario_id' => $request->user()->id,
                        'transaccion_tipo' => 'salida',
                        'transaccion_cantidad' => $cantidad,
                        'transaccion_motivo' => 'Repuesto para Orden de Trabajo',
                        'transaccion_referencia_id' => $orden->orden_trabajo_id,
                        'transaccion_referencia_type' => 'OrdenTrabajo',
                        'transaccion_notas' => "Repuesto para OT #{$orden->orden_trabajo_id}",
                    ]);
                }
            }

            if ($request->has('herramientas')) {
                foreach ($request->herramientas as $tool) {
                    $cantidad = isset($tool['cantidad']) ? (float) $tool['cantidad'] : 1.0;
                    $producto = Producto::lockForUpdate()->find($tool['producto_id']);
                    $mecanicoPrestamoId = $orden->mecanico_asignado_id ?: $this->getEmpleadoIdForUser((int) $request->user()->id);

                    if (!$producto || $producto->producto_stock_actual < $cantidad) {
                        throw ValidationException::withMessages([
                            'herramientas' => ["Stock insuficiente para herramienta ID {$tool['producto_id']}"],
                        ]);
                    }

                    $prestamo = PrestamoHerramienta::create([
                        'producto_id' => $tool['producto_id'],
                        'mecanico_id' => $mecanicoPrestamoId,
                        'admin_id' => $request->user()->id,
                        'prestamo_cantidad' => $cantidad,
                        'fecha_prestamo' => now(),
                        'estado' => 'prestado',
                        'notas' => "Herramienta usada en OT #{$orden->orden_trabajo_id}",
                    ]);

                    TransaccionInventario::create([
                        'producto_id' => $tool['producto_id'],
                        'bodega_id' => $bodegaId,
                        'usuario_id' => $request->user()->id,
                        'transaccion_tipo' => 'salida',
                        'transaccion_cantidad' => $cantidad,
                        'transaccion_motivo' => 'Prestamo de herramienta para OT',
                        'transaccion_referencia_id' => $prestamo->prestamo_id,
                        'transaccion_referencia_type' => 'PrestamoHerramienta',
                        'transaccion_notas' => "Prestamo #{$prestamo->prestamo_id} asociado a OT #{$orden->orden_trabajo_id}",
                    ]);
                }
            }

            if ($request->hasFile('foto_evidencia')) {
                $media = $mediaService->storeUploadedFile(
                    $request->file('foto_evidencia'),
                    module: 'taller',
                    entityType: 'orden_trabajo',
                    entityId: $orden->orden_trabajo_id,
                    userId: $request->user()->id,
                    notas: $request->input('notas_foto_evidencia')
                );

                $orden->foto_evidencia = $media->path;
                $orden->save();
            }

            DB::commit();

            NotificacionService::ordenCreada($orden);

            return response()->json([
                'message' => 'Orden de trabajo creada correctamente con items asociados',
                'orden' => $orden->load(['vehiculo', 'movimientos_inventario.producto'])
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error de validacion al crear la orden',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al crear la orden: ' . $e->getMessage()], 500);
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

        if (!$orden) {
            return response()->json(['message' => 'Orden de trabajo no encontrada'], 404);
        }

        if (!$this->canAccessOrden($user, $orden)) {
            return response()->json(['message' => 'No autorizado para actualizar esta orden'], 403);
        }

        // Si se intenta mover a Aprobada/Cerrada, o devolver desde Pendiente Auditoria a En Progreso, solo jefe de taller o admin
        $isAuditAction = in_array($request->estado, ['Aprobada', 'Cerrada']) || 
                         ($orden->estado === 'Pendiente Auditoria' && $request->estado === 'En Progreso');

        if ($isAuditAction && !$user->isAdmin() && !$user->isJefeDeTaller()) {
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
            'orden' => $orden
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
        
        if (!$user->isAdmin() && !$user->isJefeDeTaller()) {
            return response()->json(['message' => 'No autorizado para editar esta orden'], 403);
        }

        $orden = OrdenTrabajo::find($id);

        if (!$orden) {
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
            'orden' => $orden->load(['vehiculo', 'mecanico'])
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

        if (!$orden) {
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

                if (!$producto || $producto->producto_stock_actual < $cantidad) {
                    throw ValidationException::withMessages([
                        'repuestos' => ["Stock insuficiente para: {$producto->producto_nombre}"]
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
                'orden' => $orden->load(['vehiculo', 'mecanico', 'movimientos_inventario.producto', 'sesiones.empleado'])
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error de validación de stock',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al añadir repuestos: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            return response()->json(['message' => 'No autorizado para eliminar órdenes de trabajo'], 403);
        }

        return DB::transaction(function () use ($id) {
            $orden = OrdenTrabajo::find($id);

            if (!$orden) {
                return response()->json(['message' => 'Orden de trabajo no encontrada'], 404);
            }

            if (in_array($orden->estado, ['Abierta', 'En Progreso'])) {
                return response()->json([
                    'message' => 'No se puede eliminar: la orden de trabajo está activa',
                ], 409);
            }

            $orden->sesiones()->delete();
            $orden->movimientos_inventario()->delete();
            $orden->delete();

            return response()->json(['message' => 'Orden de trabajo eliminada correctamente']);
        });
    }

    private function resolveDefaultBodegaId(): ?int
    {
        return Bodega::value('bodega_id');
    }
}
