<?php

namespace Tests\Feature\Api;

use App\Models\Empleado;
use App\Models\ItemListaChequeo;
use App\Models\ListaChequeo;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChecklistEmpleadoCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_accepts_operador_empleado_id_and_maps_to_user_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@semanur.com']);
        $operadorUser = User::factory()->create(['role' => 'operador']);
        $operadorEmpleado = $this->crearEmpleado($operadorUser->id, 'Operador Uno');
        $vehiculo = $this->crearVehiculo();
        [$lista, $item] = $this->crearListaConItem();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/checklists', [
            'lista_chequeo_id' => $lista->id,
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'operador_id' => $operadorEmpleado->id,
            'respuestas' => [
                (string) $item->id => 'cumple',
            ],
            'observaciones_generales' => 'Checklist correcto',
        ]);

        $response->assertCreated();
        
        // After migration 2026_03_07_011156, operador_id now references empleados.id (not users.id)
        $this->assertDatabaseHas('respuestas_lista_chequeo', [
            'lista_chequeo_id' => $lista->id,
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'operador_id' => $operadorEmpleado->id,
        ]);
    }

    private function crearEmpleado(int $userId, string $nombreCompleto): Empleado
    {
        $partes = explode(' ', $nombreCompleto, 2);

        return Empleado::create([
            'nombres' => $partes[0],
            'apellidos' => $partes[1] ?? '',
            'user_id' => $userId,
            'cargo' => 'Operador',
        ]);
    }

    private function crearVehiculo(): Vehiculo
    {
        return Vehiculo::create([
            'placa' => 'CHK' . random_int(100, 999),
            'tipo' => 'tractor',
            'marca' => 'CAT',
            'modelo' => 'D6',
        ]);
    }

    private function crearListaConItem(): array
    {
        $lista = ListaChequeo::create([
            'nombre' => 'Preoperacional Tractor',
            'tipo_vehiculo' => 'tractor',
            'activo' => true,
        ]);

        $item = ItemListaChequeo::create([
            'lista_chequeo_id' => $lista->id,
            'pregunta' => 'Revisar frenos',
            'tipo_respuesta' => 'cumple_falla',
            'orden' => 1,
            'es_critico' => true,
        ]);

        return [$lista, $item];
    }
}

