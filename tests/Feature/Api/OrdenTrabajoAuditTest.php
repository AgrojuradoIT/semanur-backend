<?php

namespace Tests\Feature\Api;

use App\Models\Empleado;
use App\Models\OrdenTrabajo;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrdenTrabajoAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_jefe_taller_can_reject_order_with_notes()
    {
        // 1. Create dependencies
        $user = User::factory()->create(['role' => 'jefe_taller']);
        
        $vehiculo = Vehiculo::create([
            'placa' => 'ABC-123',
            'marca' => 'Toyota',
            'modelo' => 'Hilux',
            'tipo' => 'Camioneta',
            'estado' => 'Activo'
        ]);

        $mecanico = Empleado::create([
            'nombres' => 'Juan',
            'apellidos' => 'Perez',
            'cargo' => 'Mecánico',
            'estado' => 'Activo'
        ]);

        // 2. Create Order in Pendiente Auditoria
        $orden = OrdenTrabajo::create([
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'mecanico_asignado_id' => $mecanico->id,
            'estado' => 'Pendiente Auditoria',
            'prioridad' => 'Media',
            'descripcion' => 'Mantenimiento preventivo',
            'fecha_inicio' => now(),
        ]);

        // 3. Act: Reject order (update status to En Progreso and set notes)
        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/ordenes-trabajo/{$orden->orden_trabajo_id}/estado", [
            'estado' => 'En Progreso',
            'notas_auditoria' => 'Falta cargar el aceite en el inventario de la orden.',
        ]);

        // 4. Assert
        $response->assertStatus(200);
        $this->assertDatabaseHas('orden_trabajos', [
            'orden_trabajo_id' => $orden->orden_trabajo_id,
            'estado' => 'En Progreso',
            'notas_auditoria' => 'Falta cargar el aceite en el inventario de la orden.',
        ]);
    }

    public function test_admin_can_reject_order_with_notes()
    {
        $user = User::factory()->create(['role' => 'admin']);
        
        $vehiculo = Vehiculo::create([
            'placa' => 'XYZ-789',
            'marca' => 'Ford',
            'modelo' => 'Ranger',
            'tipo' => 'Camioneta',
            'estado' => 'Activo'
        ]);

        $mecanico = Empleado::create([
            'nombres' => 'Pedro',
            'apellidos' => 'Gomez',
            'cargo' => 'Mecánico',
            'estado' => 'Activo'
        ]);

        $orden = OrdenTrabajo::create([
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'mecanico_asignado_id' => $mecanico->id,
            'estado' => 'Pendiente Auditoria',
            'prioridad' => 'Media',
            'descripcion' => 'Revisión general',
            'fecha_inicio' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/ordenes-trabajo/{$orden->orden_trabajo_id}/estado", [
            'estado' => 'En Progreso',
            'notas_auditoria' => 'Revisar filtro de aire.',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orden_trabajos', [
            'orden_trabajo_id' => $orden->orden_trabajo_id,
            'estado' => 'En Progreso',
            'notas_auditoria' => 'Revisar filtro de aire.',
        ]);
    }

    public function test_mechanic_cannot_approve_or_reject_order()
    {
        $user = User::factory()->create(['role' => 'operativo']);
        $mecanico = Empleado::create([
            'user_id' => $user->id,
            'nombres' => 'Carlos',
            'apellidos' => 'Lopez',
            'cargo' => 'Mecánico',
            'estado' => 'Activo'
        ]);

        $vehiculo = Vehiculo::create([
            'placa' => 'AAA-111',
            'marca' => 'Toyota',
            'modelo' => 'Hilux',
            'tipo' => 'Camioneta',
            'estado' => 'Activo'
        ]);

        $orden = OrdenTrabajo::create([
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'mecanico_asignado_id' => $mecanico->id,
            'estado' => 'Pendiente Auditoria',
            'prioridad' => 'Media',
            'descripcion' => 'Mantenimiento preventivo',
            'fecha_inicio' => now(),
        ]);

        // Attempting to approve order should fail for mechanic
        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/ordenes-trabajo/{$orden->orden_trabajo_id}/estado", [
            'estado' => 'Aprobada',
        ]);
        $response->assertStatus(403);

        // Attempting to reject order (set back to En Progreso) should fail for mechanic
        $response2 = $this->actingAs($user, 'sanctum')->patchJson("/api/ordenes-trabajo/{$orden->orden_trabajo_id}/estado", [
            'estado' => 'En Progreso',
            'notas_auditoria' => 'Intento malicioso de auto-rechazo',
        ]);
        $response2->assertStatus(403);
    }
}

