<?php

namespace Tests\Feature\InventoryOrders;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidoContabilidadAltaTest extends TestCase
{
    use RefreshDatabase;

    public function test_contabilidad_role_is_accepted_by_employee_creation(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $email = 'contabilidad-'.Str::lower(Str::random(8)).'@example.com';

        $this->postJson('/api/empleados', [
            'nombres' => 'Ana',
            'apellidos' => 'Contable',
            'crear_usuario' => true,
            'email' => $email,
            'password' => 'Clave#Segura2026',
            'role' => 'contabilidad',
        ])->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'role' => 'contabilidad',
        ]);
    }

    public function test_unknown_role_is_rejected_on_employee_creation(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/empleados', [
            'nombres' => 'Ana',
            'apellidos' => 'Contable',
            'crear_usuario' => true,
            'email' => 'otro-'.Str::lower(Str::random(8)).'@example.com',
            'password' => 'Clave#Segura2026',
            'role' => 'contador',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }
}
