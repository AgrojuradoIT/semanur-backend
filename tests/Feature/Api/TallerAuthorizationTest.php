<?php

namespace Tests\Feature\Api;

use App\Models\Empleado;
use App\Models\OrdenTrabajo;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TallerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_assigned_user_cannot_view_work_order(): void
    {
        $mecanico = User::factory()->create(['role' => 'mecanico', 'permisos' => ['taller.read']]);
        $mecanicoEmpleado = $this->crearEmpleadoParaUsuario($mecanico->id, 'Mecanico Uno');
        $usuarioNoAsignado = User::factory()->create(['role' => 'operador', 'permisos' => ['taller.read']]);
        $orden = $this->crearOrdenTrabajo($mecanicoEmpleado->id);

        Sanctum::actingAs($usuarioNoAsignado);

        $this->getJson('/api/ordenes-trabajo/' . $orden->orden_trabajo_id)
            ->assertForbidden()
            ->assertJson(['message' => 'No autorizado para ver esta orden']);
    }

    public function test_non_assigned_user_cannot_update_work_order_status(): void
    {
        $mecanico = User::factory()->create(['role' => 'mecanico', 'permisos' => ['taller.write']]);
        $mecanicoEmpleado = $this->crearEmpleadoParaUsuario($mecanico->id, 'Mecanico Uno');
        $usuarioNoAsignado = User::factory()->create(['role' => 'operador', 'permisos' => ['taller.write']]);
        $orden = $this->crearOrdenTrabajo($mecanicoEmpleado->id);

        Sanctum::actingAs($usuarioNoAsignado);

        $this->patchJson('/api/ordenes-trabajo/' . $orden->orden_trabajo_id . '/estado', [
            'estado' => 'En Progreso',
        ])
            ->assertForbidden()
            ->assertJson(['message' => 'No autorizado para actualizar esta orden']);
    }

    public function test_admin_can_view_and_update_work_order(): void
    {
        $mecanico = User::factory()->create(['role' => 'mecanico', 'permisos' => ['taller.write']]);
        $mecanicoEmpleado = $this->crearEmpleadoParaUsuario($mecanico->id, 'Mecanico Uno');
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@semanur.com']);
        $orden = $this->crearOrdenTrabajo($mecanicoEmpleado->id);

        Sanctum::actingAs($admin);

        $this->getJson('/api/ordenes-trabajo/' . $orden->orden_trabajo_id)
            ->assertOk();

        $this->patchJson('/api/ordenes-trabajo/' . $orden->orden_trabajo_id . '/estado', [
            'estado' => 'En Progreso',
        ])->assertOk();

        $this->assertDatabaseHas('orden_trabajos', [
            'orden_trabajo_id' => $orden->orden_trabajo_id,
            'estado' => 'En Progreso',
        ]);
    }

    public function test_auxiliar_bodega_can_view_all_work_orders(): void
    {
        $mecanico = User::factory()->create(['role' => 'mecanico', 'permisos' => ['taller.write']]);
        $mecanicoEmpleado = $this->crearEmpleadoParaUsuario($mecanico->id, 'Mecanico Uno');
        $auxiliar = User::factory()->create(['role' => 'auxiliar_bodega', 'permisos' => ['taller.read']]);
        $orden = $this->crearOrdenTrabajo($mecanicoEmpleado->id);

        Sanctum::actingAs($auxiliar);

        // Can list all work orders
        $this->getJson('/api/ordenes-trabajo')
            ->assertOk()
            ->assertJsonFragment(['orden_trabajo_id' => $orden->orden_trabajo_id]);

        // Can view specific work order detail
        $this->getJson('/api/ordenes-trabajo/' . $orden->orden_trabajo_id)
            ->assertOk();
    }

    public function test_non_assigned_user_cannot_start_work_session_on_assigned_order(): void
    {
        $mecanico = User::factory()->create(['role' => 'mecanico', 'permisos' => ['taller.write']]);
        $mecanicoEmpleado = $this->crearEmpleadoParaUsuario($mecanico->id, 'Mecanico Uno');
        $usuarioNoAsignado = User::factory()->create(['role' => 'operador', 'permisos' => ['taller.write']]);
        $this->crearEmpleadoParaUsuario($usuarioNoAsignado->id, 'No Asignado');
        $orden = $this->crearOrdenTrabajo($mecanicoEmpleado->id);

        Sanctum::actingAs($usuarioNoAsignado);

        $this->postJson('/api/sesiones-trabajo/start', [
            'orden_trabajo_id' => $orden->orden_trabajo_id,
        ])
            ->assertForbidden()
            ->assertJson(['message' => 'No autorizado para iniciar sesion en esta orden']);
    }

    public function test_user_cannot_stop_another_users_session(): void
    {
        $mecanico = User::factory()->create(['role' => 'mecanico', 'permisos' => ['taller.write']]);
        $mecanicoEmpleado = $this->crearEmpleadoParaUsuario($mecanico->id, 'Mecanico Uno');
        $usuarioNoAsignado = User::factory()->create(['role' => 'operador', 'permisos' => ['taller.write']]);
        $this->crearEmpleadoParaUsuario($usuarioNoAsignado->id, 'No Asignado');
        $orden = $this->crearOrdenTrabajo($mecanicoEmpleado->id);

        $sesion = WorkSession::create([
            'empleado_id' => $mecanicoEmpleado->id,
            'orden_trabajo_id' => $orden->orden_trabajo_id,
            'fecha_inicio' => now()->subHour(),
        ]);

        Sanctum::actingAs($usuarioNoAsignado);

        $this->postJson('/api/sesiones-trabajo/' . $sesion->sesion_id . '/stop', [
            'notes' => 'Intento no autorizado',
        ])
            ->assertForbidden()
            ->assertJson(['message' => 'No autorizado para finalizar esta sesion']);
    }

    public function test_assigned_mechanic_can_start_and_stop_own_session(): void
    {
        $mecanico = User::factory()->create(['role' => 'mecanico', 'permisos' => ['taller.write']]);
        $mecanicoEmpleado = $this->crearEmpleadoParaUsuario($mecanico->id, 'Mecanico Uno');
        $orden = $this->crearOrdenTrabajo($mecanicoEmpleado->id);

        Sanctum::actingAs($mecanico);

        $startResponse = $this->postJson('/api/sesiones-trabajo/start', [
            'orden_trabajo_id' => $orden->orden_trabajo_id,
        ]);

        $startResponse->assertCreated();
        $sessionId = $startResponse->json('session.sesion_id');

        $this->postJson('/api/sesiones-trabajo/' . $sessionId . '/stop', [
            'notas' => 'Trabajo terminado',
        ])->assertOk();

        $this->assertDatabaseHas('sesiones_trabajo', [
            'sesion_id' => $sessionId,
            'empleado_id' => $mecanicoEmpleado->id,
        ]);
    }

    private function crearEmpleadoParaUsuario(int $userId, string $nombreCompleto): Empleado
    {
        $partes = explode(' ', $nombreCompleto, 2);

        return Empleado::create([
            'nombres' => $partes[0],
            'apellidos' => $partes[1] ?? '',
            'user_id' => $userId,
            'cargo' => 'Mecanico',
        ]);
    }

    private function crearOrdenTrabajo(int $mecanicoEmpleadoId): OrdenTrabajo
    {
        $vehiculo = Vehiculo::create([
            'placa' => 'PLT' . random_int(100, 999),
            'tipo' => 'tractor',
            'marca' => 'CAT',
            'modelo' => 'D6',
        ]);

        return OrdenTrabajo::create([
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'mecanico_asignado_id' => $mecanicoEmpleadoId,
            'fecha_inicio' => now(),
            'estado' => 'Abierta',
            'prioridad' => 'Media',
            'descripcion' => 'Orden de prueba',
        ]);
    }
}
