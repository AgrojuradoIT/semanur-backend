<?php

namespace App\Services;

use App\Models\Notificacion;
use App\Models\OrdenTrabajo;
use App\Models\Programacion;
use App\Models\Novedad;
use App\Models\Producto;
use App\Models\User;
use App\Models\Empleado;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class NotificacionService
{
    /**
     * Crea una notificación con deduplicación básica (1 hora)
     * para evitar spam del mismo tipo y recurso al mismo usuario.
     */
    public static function crear(int $userId, string $tipo, string $titulo, string $mensaje, ?string $relacionadoId = null, string $prioridad = 'media'): void
    {
        try {
            $existe = Notificacion::where('user_id', $userId)
                ->where('tipo', $tipo)
                ->where('relacionado_id', $relacionadoId)
                ->whereNull('fecha_leido')
                ->where('created_at', '>=', Carbon::now()->subHour())
                ->exists();

            if (!$existe) {
                Notificacion::create([
                    'user_id' => $userId,
                    'tipo' => $tipo,
                    'titulo' => $titulo,
                    'mensaje' => $mensaje,
                    'relacionado_id' => $relacionadoId,
                    'prioridad' => $prioridad
                ]);
            }
        } catch (\Exception $e) {
            Log::error("NotificacionService::crear Error: " . $e->getMessage());
        }
    }

    /**
     * Resuelve el user_id a partir de un empleado_id
     */
    private static function getUserIdFromEmpleado(?int $empleadoId): ?int
    {
        if (!$empleadoId) return null;
        return Empleado::where('id', $empleadoId)->value('user_id');
    }

    /**
     * Notifica al mecánico cuando se le asigna una nueva OT
     */
    public static function ordenCreada(OrdenTrabajo $orden): void
    {
        $userId = self::getUserIdFromEmpleado($orden->mecanico_asignado_id);
        if (!$userId) return;

        $placa = $orden->vehiculo ? $orden->vehiculo->placa : 'Sin Vehículo';
        
        self::crear(
            $userId,
            'orden_creada',
            "Nueva OT #{$orden->orden_trabajo_id} asignada",
            "Vehículo: {$placa}. Descripción: {$orden->descripcion}",
            (string) $orden->orden_trabajo_id,
            $orden->prioridad === 'Alta' ? 'alta' : 'media'
        );
    }

    /**
     * Notifica cambios de estado en la OT (a jefes o al mecánico según la transición)
     */
    public static function ordenEstadoCambiado(OrdenTrabajo $orden, string $estadoAnterior): void
    {
        if ($orden->estado === $estadoAnterior) return;

        $placa = $orden->vehiculo ? $orden->vehiculo->placa : 'Sin Vehículo';
        $mecanicoUserId = self::getUserIdFromEmpleado($orden->mecanico_asignado_id);
        $mecanicoNombre = $orden->mecanico ? $orden->mecanico->nombre_completo : 'Mecánico';

        $jefes = User::whereIn('role', ['admin', 'jefe_taller'])->pluck('id');

        // Transición a En Progreso (Iniciado por el mecánico, notifica al jefe)
        if ($orden->estado === 'En Progreso') {
            if ($estadoAnterior === 'Pendiente Auditoria' && $mecanicoUserId) {
                // Fue devuelta por el jefe, notifica al mecánico
                self::crear(
                    $mecanicoUserId,
                    'orden_estado',
                    "OT #{$orden->orden_trabajo_id} Devuelta",
                    "La OT fue devuelta de auditoría. Revisa las observaciones.",
                    (string) $orden->orden_trabajo_id,
                    'alta'
                );
            } else {
                // Iniciada normalmente, notifica a los jefes
                foreach ($jefes as $jefeId) {
                    if ($jefeId !== $mecanicoUserId) { // No notificarse a sí mismo si es el jefe
                        self::crear(
                            $jefeId,
                            'orden_estado',
                            "OT #{$orden->orden_trabajo_id} En Progreso",
                            "El mecánico {$mecanicoNombre} inició trabajo en la OT del vehículo {$placa}.",
                            (string) $orden->orden_trabajo_id,
                            'media'
                        );
                    }
                }
            }
        }

        // Transición a Pendiente Auditoría (Mecánico terminó, notifica al jefe)
        if ($orden->estado === 'Pendiente Auditoria') {
            foreach ($jefes as $jefeId) {
                self::crear(
                    $jefeId,
                    'orden_estado',
                    "OT #{$orden->orden_trabajo_id} Lista para Auditoría",
                    "El mecánico {$mecanicoNombre} terminó la OT del vehículo {$placa}. Requiere revisión.",
                    (string) $orden->orden_trabajo_id,
                    'alta'
                );
            }
        }

        // Transición a Aprobada o Cerrada (Jefe aprobó, notifica al mecánico)
        if (in_array($orden->estado, ['Aprobada', 'Cerrada']) && $mecanicoUserId) {
            self::crear(
                $mecanicoUserId,
                'orden_estado',
                "OT #{$orden->orden_trabajo_id} {$orden->estado}",
                "Tu trabajo en el vehículo {$placa} ha sido revisado y está en estado: {$orden->estado}.",
                (string) $orden->orden_trabajo_id,
                'media'
            );
        }
    }

    /**
     * Notifica al nuevo mecánico cuando se reasigna una OT
     */
    public static function ordenReasignada(OrdenTrabajo $orden, ?int $mecanicoAnteriorId): void
    {
        if ($orden->mecanico_asignado_id === $mecanicoAnteriorId) return;

        $userId = self::getUserIdFromEmpleado($orden->mecanico_asignado_id);
        if (!$userId) return;

        $placa = $orden->vehiculo ? $orden->vehiculo->placa : 'Sin Vehículo';
        
        self::crear(
            $userId,
            'orden_reasignada',
            "OT #{$orden->orden_trabajo_id} Reasignada a ti",
            "Se te ha reasignado la OT del vehículo {$placa}.",
            (string) $orden->orden_trabajo_id,
            'alta'
        );
    }

    /**
     * Notifica al mecánico sobre una programación asignada
     */
    public static function programacionAsignada(Programacion $prog): void
    {
        $userId = self::getUserIdFromEmpleado($prog->empleado_id);
        if (!$userId) return;

        self::crear(
            $userId,
            'programacion_asignada',
            "Nueva labor programada",
            "Fecha: {$prog->fecha->format('d/m/Y')}. Labor: {$prog->labor}.",
            (string) $prog->id,
            'media'
        );
    }

    /**
     * Notifica a jefes y admins sobre una novedad reportada en campo
     */
    public static function novedadReportada(Novedad $novedad): void
    {
        $jefes = User::whereIn('role', ['admin', 'jefe_taller'])->pluck('id');
        $placa = $novedad->vehiculo ? $novedad->vehiculo->placa : 'Sin Vehículo';
        $mecanicoNombre = $novedad->empleado ? $novedad->empleado->nombre_completo : 'Mecánico';
        
        $prioridadNotif = strtolower($novedad->prioridad) === 'urgente' ? 'alta' : 'media';

        foreach ($jefes as $jefeId) {
            self::crear(
                $jefeId,
                'novedad_reportada',
                "Novedad Reportada: {$placa}",
                "Reportado por: {$mecanicoNombre}. Descripción: {$novedad->descripcion}",
                (string) $novedad->id,
                $prioridadNotif
            );
        }
    }

    /**
     * Notifica a admins y bodega sobre stock bajo
     */
    public static function stockBajo(Producto $producto): void
    {
        $responsables = User::whereIn('role', ['admin', 'jefe_taller', 'auxiliar_bodega'])->pluck('id');
        
        $prioridad = $producto->producto_stock_actual <= 0 ? 'alta' : 'media';

        foreach ($responsables as $userId) {
            self::crear(
                $userId,
                'stock_bajo',
                'Stock bajo: ' . $producto->producto_nombre,
                "El producto {$producto->producto_nombre} ({$producto->producto_sku}) tiene {$producto->producto_stock_actual} unidades. Mínimo requerido: {$producto->producto_alerta_stock_minimo}.",
                (string) $producto->producto_id,
                $prioridad
            );
        }
    }
}
