<?php

namespace Tests\Feature\Api;

use App\Models\Empleado;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehiculoAssignmentCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_accepts_mixed_empleado_and_legacy_user_ids_when_not_ambiguous(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@semanur.com']);
        $operadorUser = User::factory()->create(['role' => 'operador']);
        $mecanicoUser = User::factory()->create(['role' => 'mecanico']);

        $operadorEmpleado = $this->crearEmpleado($operadorUser->id, 'Operador Uno');
        $mecanicoEmpleado = $this->crearEmpleado($mecanicoUser->id, 'Mecanico Uno');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/vehiculos', [
            'placa' => 'ABC123',
            'tipo' => 'tractor',
            'marca' => 'CAT',
            'modelo' => 'D6',
            'operador_asignado_id' => $operadorEmpleado->id,
            'mecanico_asignado_id' => $mecanicoEmpleado->id,
        ]);

        $response->assertCreated();

        $vehiculoId = $response->json('vehiculo.vehiculo_id');
        $this->assertDatabaseHas('vehiculos', [
            'vehiculo_id' => $vehiculoId,
            'operador_asignado_id' => $operadorEmpleado->id,
            'mecanico_asignado_id' => $mecanicoEmpleado->id,
        ]);

        $response->assertJsonPath('vehiculo.operador.id', $operadorEmpleado->id);
        $response->assertJsonPath('vehiculo.mecanico.id', $mecanicoEmpleado->id);
        $response->assertJsonPath('vehiculo.operador.name', $operadorEmpleado->name);
        $response->assertJsonPath('vehiculo.mecanico.name', $mecanicoEmpleado->name);
    }

    public function test_update_accepts_empleado_ids_directly(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@semanur.com']);
        $operadorUser = User::factory()->create(['role' => 'operador']);
        $mecanicoUser = User::factory()->create(['role' => 'mecanico']);

        $operadorEmpleado = $this->crearEmpleado($operadorUser->id, 'Operador Dos');
        $mecanicoEmpleado = $this->crearEmpleado($mecanicoUser->id, 'Mecanico Dos');

        $vehiculo = Vehiculo::create([
            'placa' => 'DEF456',
            'tipo' => 'tractor',
            'marca' => 'Komatsu',
            'modelo' => 'ZX',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/vehiculos/' . $vehiculo->vehiculo_id, [
            'operador_asignado_id' => $operadorEmpleado->id,
            'mecanico_asignado_id' => $mecanicoEmpleado->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('vehiculos', [
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'operador_asignado_id' => $operadorEmpleado->id,
            'mecanico_asignado_id' => $mecanicoEmpleado->id,
        ]);
    }

    private function crearEmpleado(int $userId, string $nombreCompleto): Empleado
    {
        $partes = explode(' ', $nombreCompleto, 2);

        return Empleado::create([
            'nombres' => $partes[0],
            'apellidos' => $partes[1] ?? '',
            'cargo' => 'Operativo',
            'user_id' => $userId,
        ]);
    }
}
