<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChecklistApiController;
use App\Http\Controllers\Api\CombustibleApiController;
use App\Http\Controllers\Api\HorometroApiController;
use App\Http\Controllers\Api\MediaApiController;
use App\Http\Controllers\Api\MovimientoInventarioApiController;
use App\Http\Controllers\Api\OrdenTrabajoApiController;
use App\Http\Controllers\Api\PrestamoApiController;
use App\Http\Controllers\Api\ProductoApiController;
use App\Http\Controllers\Api\VehiculoApiController;
use App\Http\Controllers\Api\VehiculoDocumentoApiController;
use App\Http\Controllers\Api\PreoperacionalV2Controller;
use App\Models\Cargo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

// Rutas públicas
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('/app/version', [\App\Http\Controllers\Api\AppVersionController::class, 'checkVersion']);
Route::get('/app/download', [\App\Http\Controllers\Api\AppVersionController::class, 'download']);

// Rutas protegidas (comunes a todos los usuarios autenticados)
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Broadcast::routes();
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    Route::post('/user/fcm-token', [AuthController::class, 'updateFcmToken']);

    // ─── Notificaciones ────────────────────────────────
    Route::middleware('permission:notificaciones.read')->group(function () {
        Route::get('notifications/unread-count', [\App\Http\Controllers\Api\NotificacionApiController::class, 'unreadCount']);
        Route::get('notifications', [\App\Http\Controllers\Api\NotificacionApiController::class, 'index']);
    });

    Route::middleware('permission:notificaciones.write')->group(function () {
        Route::post('notifications/{id}/read', [\App\Http\Controllers\Api\NotificacionApiController::class, 'markAsRead']);
        Route::post('notifications/read-all', [\App\Http\Controllers\Api\NotificacionApiController::class, 'markAllAsRead']);
        Route::post('notifications/sync-read', [\App\Http\Controllers\Api\NotificacionApiController::class, 'syncRead']);
        Route::delete('notifications/read', [\App\Http\Controllers\Api\NotificacionApiController::class, 'destroyRead']);
        Route::delete('notifications/{id}', [\App\Http\Controllers\Api\NotificacionApiController::class, 'destroy']);
    });

    // ─── Combustible ───────────────────────────────────
    Route::get('/combustible', [CombustibleApiController::class, 'index'])->middleware('permission:combustible.read,analitica.read');
    Route::get('/combustible/resumen', [CombustibleApiController::class, 'summary'])->middleware('permission:combustible.read,analitica.read');
    Route::get('/combustible/reportes', [CombustibleApiController::class, 'reportes'])->middleware('permission:combustible.read,analitica.read');
    
    Route::middleware('permission:combustible.write')->group(function () {
        Route::post('/combustible', [CombustibleApiController::class, 'store'])->middleware('role:admin,jefe_taller,auxiliar_bodega');
        Route::put('/combustible/{id}', [CombustibleApiController::class, 'update'])->middleware('role:admin');
        Route::delete('/combustible/{id}', [CombustibleApiController::class, 'destroy'])->middleware('role:admin');
    });

    // ─── Checklists ────────────────────────────────────
    Route::get('/checklists', [ChecklistApiController::class, 'index'])->middleware('permission:checklists.read,flota.read');
    Route::get('/checklists/history', [ChecklistApiController::class, 'history'])->middleware('permission:checklists.read,flota.read');
    Route::post('/checklists', [ChecklistApiController::class, 'store'])->middleware('permission:checklists.write');

    // ─── Inventario (Productos) ────────────────────────
    Route::get('/productos', [ProductoApiController::class, 'index'])->middleware('permission:inventario.read,taller.read,prestamos.read,combustible.read,analitica.read');
    Route::get('/productos/buscar', [ProductoApiController::class, 'search'])->middleware('permission:inventario.read,taller.read,prestamos.read,combustible.read,analitica.read');
    Route::get('/productos/{id}', [ProductoApiController::class, 'show'])->middleware('permission:inventario.read,taller.read,prestamos.read,combustible.read,analitica.read');
    
    Route::middleware('permission:inventario.write')->group(function () {
        Route::post('/productos', [ProductoApiController::class, 'store'])->middleware('role:admin,jefe_taller,auxiliar_bodega');
        Route::put('/productos/{id}', [ProductoApiController::class, 'update'])->middleware('role:admin');
        Route::delete('/productos/{id}', [ProductoApiController::class, 'destroy'])->middleware('role:admin');
        Route::post('/productos/import', [ProductoApiController::class, 'import'])->middleware('role:admin,jefe_taller,auxiliar_bodega');
        Route::post('/inventario/import-compras', [\App\Http\Controllers\Api\InventarioImportApiController::class, 'importCompras'])->middleware('role:admin,jefe_taller,auxiliar_bodega');
    });

    // ─── Taller (OT + Sesiones) ────────────────────────
    Route::get('/ordenes-trabajo', [OrdenTrabajoApiController::class, 'index'])->middleware('permission:taller.read,analitica.read');
    Route::get('/ordenes-trabajo/{id}', [OrdenTrabajoApiController::class, 'show'])->middleware('permission:taller.read');
    Route::get('/sesiones-trabajo/active', [\App\Http\Controllers\Api\WorkSessionApiController::class, 'activeSession'])->middleware('permission:taller.read,flota.read');
    
    Route::middleware('permission:taller.write')->group(function () {
        Route::post('/ordenes-trabajo', [OrdenTrabajoApiController::class, 'store']);
        Route::put('/ordenes-trabajo/{id}', [OrdenTrabajoApiController::class, 'update']);
        Route::patch('/ordenes-trabajo/{id}/estado', [OrdenTrabajoApiController::class, 'updateStatus']);
        Route::post('/ordenes-trabajo/{id}/repuestos', [OrdenTrabajoApiController::class, 'addRepuestos'])->middleware('role:admin,jefe_taller,auxiliar_bodega');
        Route::delete('/ordenes-trabajo/{id}', [OrdenTrabajoApiController::class, 'destroy'])->middleware('role:admin');
        Route::post('/sesiones-trabajo/start', [\App\Http\Controllers\Api\WorkSessionApiController::class, 'start']);
        Route::post('/sesiones-trabajo/{id}/stop', [\App\Http\Controllers\Api\WorkSessionApiController::class, 'stop']);
    });

    // ─── Flota (Vehículos + Horómetro + Documentos) ───
    Route::get('/vehiculos', [VehiculoApiController::class, 'index'])->middleware('permission:flota.read,taller.read,checklists.read,combustible.read,analitica.read');
    Route::get('/vehiculos/{id}', [VehiculoApiController::class, 'show'])->middleware('permission:flota.read,taller.read,checklists.read,combustible.read,analitica.read');
    Route::get('/vehiculos/{vehiculoId}/documentos', [VehiculoDocumentoApiController::class, 'index'])->middleware('permission:flota.read,taller.read,checklists.read');
    Route::get('/vehiculos/{vehiculoId}/documentos/{id}', [VehiculoDocumentoApiController::class, 'show'])->middleware('permission:flota.read,taller.read,checklists.read');
    Route::get('/vehiculos/{id}/horometro', [HorometroApiController::class, 'index'])->middleware('permission:flota.read,taller.read,checklists.read');

    Route::middleware('permission:flota.write')->group(function () {
        Route::post('/vehiculos', [VehiculoApiController::class, 'store']);
        Route::post('/vehiculos/imagen', [VehiculoApiController::class, 'uploadImage']);
        Route::put('/vehiculos/{id}', [VehiculoApiController::class, 'update']);
        Route::delete('/vehiculos/{id}', [VehiculoApiController::class, 'destroy'])->middleware('role:admin');
        Route::post('/vehiculos/{vehiculoId}/documentos', [VehiculoDocumentoApiController::class, 'store']);
        Route::put('/vehiculos/{vehiculoId}/documentos/{id}', [VehiculoDocumentoApiController::class, 'update']);
        Route::delete('/vehiculos/{vehiculoId}/documentos/{id}', [VehiculoDocumentoApiController::class, 'destroy']);
        Route::post('/horometro', [HorometroApiController::class, 'store']);
    });

    // ─── Cargos (lista centralizada) ─────────────────────
    Route::get('/cargos', function () {
        return response()->json(Cache::remember('api:cargos', now()->addHour(), function () {
            return [
                'cargos' => Cargo::where('activo', true)->orderBy('orden')->pluck('nombre'),
            ];
        }));
    });

    // ─── Personal (Empleados + Programación) ───────────
    // GET /empleados es público para todos los usuarios autenticados (dropdowns)
    Route::get('/empleados', [\App\Http\Controllers\Api\EmpleadoApiController::class, 'index']);
    
    Route::get('/programacion', [\App\Http\Controllers\Api\ProgramacionApiController::class, 'index'])->middleware('permission:personal.read');

    Route::middleware('permission:personal.write')->group(function () {
        Route::apiResource('/empleados', \App\Http\Controllers\Api\EmpleadoApiController::class)->except(['index']);
        Route::post('/programacion', [\App\Http\Controllers\Api\ProgramacionApiController::class, 'store']);
        Route::post('/programacion/novedad', [\App\Http\Controllers\Api\ProgramacionApiController::class, 'novedad']);
        Route::put('/programacion/{id}', [\App\Http\Controllers\Api\ProgramacionApiController::class, 'update']);
        Route::delete('/programacion/{id}', [\App\Http\Controllers\Api\ProgramacionApiController::class, 'destroy']);
    });

    // ─── Préstamos ─────────────────────────────────────
    Route::get('/prestamos', [PrestamoApiController::class, 'index'])->middleware('permission:prestamos.read');
    
    Route::middleware('permission:prestamos.write')->group(function () {
        Route::post('/prestamos', [PrestamoApiController::class, 'store'])->middleware('role:admin,jefe_taller,auxiliar_bodega');
        Route::post('/prestamos/{id}/devolver', [PrestamoApiController::class, 'devolver']);
    });

    // ─── Movimientos de Inventario ─────────────────────
    Route::get('/movimientos', [MovimientoInventarioApiController::class, 'index'])->middleware('permission:movimientos.read');
    Route::post('/movimientos', [MovimientoInventarioApiController::class, 'store'])->middleware('permission:movimientos.write', 'role:admin,jefe_taller,auxiliar_bodega');

    // ─── Usuarios ──────────────────────────────────────
    Route::get('/users', [AuthController::class, 'index'])->middleware('permission:usuarios.read');

    // ─── Media ─────────────────────────────────────────
    Route::get('/media', [MediaApiController::class, 'index'])->middleware('permission:media.read');
    Route::middleware('permission:media.write')->group(function () {
        Route::post('/media', [MediaApiController::class, 'store']);
        Route::delete('/media/{id}', [MediaApiController::class, 'destroy']);
    });

    // ─── Preoperacionales V2 ─────────────────────────────
    Route::prefix('v2/preoperacionales')->group(function () {
        Route::get('/templates', [PreoperacionalV2Controller::class, 'templates'])->middleware('permission:checklists.read,flota.read');
        Route::get('/pendientes-hoy', [PreoperacionalV2Controller::class, 'pendientesHoy'])->middleware('permission:checklists.read,flota.read');
        Route::get('/semanas', [PreoperacionalV2Controller::class, 'indexSemanas'])->middleware('permission:checklists.read,flota.read');
        Route::get('/semanas/{id}', [PreoperacionalV2Controller::class, 'showSemana'])->middleware('permission:checklists.read,flota.read');
        
        Route::middleware('permission:checklists.write')->group(function () {
            Route::post('/semanas', [PreoperacionalV2Controller::class, 'storeSemana']);
            Route::post('/semanas/{semanaId}/dias/{diaSemana}', [PreoperacionalV2Controller::class, 'submitDailyForm']);
            Route::put('/semanas/{id}/fuera-servicio', [PreoperacionalV2Controller::class, 'markFueraServicio']);
        });
    });

    // ─── Analítica ─────────────────────────────────────
    Route::middleware('permission:analitica.read')->group(function () {
        Route::get('/dashboard/all', [\App\Http\Controllers\Api\AnalyticsApiController::class, 'getDashboard']);
        Route::get('/history/all', [\App\Http\Controllers\Api\HistoryApiController::class, 'getHistoryAll'])->middleware('role:admin');
        Route::get('/analytics/summary', [\App\Http\Controllers\Api\AnalyticsApiController::class, 'getSummary']);
        Route::get('/analytics/fuel', [\App\Http\Controllers\Api\AnalyticsApiController::class, 'getFuelMonthly']);
        Route::get('/analytics/maintenance', [\App\Http\Controllers\Api\AnalyticsApiController::class, 'getMaintenanceByVehicle']);
        Route::get('/analytics/fuel-stock', [\App\Http\Controllers\Api\AnalyticsApiController::class, 'getFuelStock']);
    });
});
