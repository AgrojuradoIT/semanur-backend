<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_login_issues_a_device_token_for_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'operator@semanur.com']);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'phpunit',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_api_login_rejects_invalid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'operator@semanur.com']);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'phpunit',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_authenticated_api_user_endpoint_returns_the_current_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phpunit')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email);
    }

    public function test_authenticated_api_user_can_update_supported_profile_fields(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@semanur.com',
            'phone' => null,
            'license_number' => null,
        ]);
        $token = $user->createToken('phpunit')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/user/profile', [
                'name' => 'Updated Operator',
                'phone' => '3001234567',
                'license_number' => 'LIC-123',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Updated Operator')
            ->assertJsonPath('phone', '3001234567')
            ->assertJsonPath('license_number', 'LIC-123');

        $user->refresh();

        $this->assertSame('Updated Operator', $user->name);
        $this->assertSame('operator@semanur.com', $user->email);
        $this->assertSame('3001234567', $user->phone);
        $this->assertSame('LIC-123', $user->license_number);
    }

    public function test_api_logout_revokes_the_current_device_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phpunit')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Sesion cerrada correctamente');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_filament_panel_uses_its_own_login_surface(): void
    {
        $this->get('/panel')
            ->assertRedirect('/panel/login');

        $this->get('/panel/login')
            ->assertOk();
    }
}
