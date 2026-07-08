<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthUsersAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_operador_cannot_access_users_endpoint(): void
    {
        $operador = User::factory()->create([
            'role' => 'operador',
            'email' => 'operador@semanur.com',
            'permisos' => ['usuarios'],
        ]);

        Sanctum::actingAs($operador);

        $response = $this->getJson('/api/users');

        $response
            ->assertForbidden()
            ->assertJson([
                'message' => 'No autorizado para consultar usuarios',
            ]);
    }

    public function test_admin_can_access_users_endpoint_with_limited_fields(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin Root',
            'role' => 'admin',
            'email' => 'admin@semanur.com',
            'phone' => '3001234567',
            'license_number' => 'LIC-123',
            'cargo' => 'Administrador',
        ]);

        User::factory()->create([
            'name' => 'Operador Uno',
            'role' => 'operador',
            'email' => 'operador1@semanur.com',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/users');

        $response->assertOk();
        $users = $response->json();

        $this->assertNotEmpty($users);
        $this->assertArrayHasKey('id', $users[0]);
        $this->assertArrayHasKey('name', $users[0]);
        $this->assertArrayHasKey('email', $users[0]);
        $this->assertArrayHasKey('role', $users[0]);
        $this->assertArrayHasKey('phone', $users[0]);
        $this->assertArrayHasKey('license_number', $users[0]);
        $this->assertArrayHasKey('cargo', $users[0]);
        $this->assertArrayNotHasKey('password', $users[0]);
        $this->assertArrayNotHasKey('remember_token', $users[0]);
    }

    public function test_almacenista_can_access_users_endpoint(): void
    {
        $almacenista = User::factory()->create([
            'role' => 'almacenista',
            'email' => 'almacen@semanur.com',
            'permisos' => ['usuarios'],
        ]);

        Sanctum::actingAs($almacenista);

        $response = $this->getJson('/api/users');

        $response->assertOk();
    }
}

