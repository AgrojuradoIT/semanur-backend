<?php

namespace Tests\Feature\Api;

use App\Models\Empleado;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class BackendSecurityRemediationTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_writer_cannot_create_a_technical_user_or_admin(): void
    {
        $writer = User::factory()->create([
            'role' => 'jefe_taller',
            'permisos' => ['personal.write'],
        ]);
        Sanctum::actingAs($writer);

        $this->postJson('/api/empleados', [
            'nombres' => 'Intento',
            'apellidos' => 'Escalacion',
            'crear_usuario' => true,
            'email' => 'escalacion@semanur.com',
            'password' => 'Strong!Password123',
            'role' => 'admin',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'escalacion@semanur.com']);
        $this->assertDatabaseMissing('empleados', ['nombres' => 'Intento']);
    }

    public function test_authenticated_employee_directory_is_minimized_without_personal_permission(): void
    {
        $viewer = User::factory()->create(['role' => 'operador', 'permisos' => []]);
        Empleado::create([
            'nombres' => 'Ana',
            'apellidos' => 'Campo',
            'documento' => 'SECRET-123',
            'telefono' => '3000000000',
            'direccion' => 'Private address',
            'licencia_conduccion' => 'LICENSE-123',
            'estado' => 'activo',
        ]);
        Sanctum::actingAs($viewer);

        $employee = $this->getJson('/api/empleados')->assertOk()->json('0');

        $this->assertSame('Ana Campo', $employee['name']);
        foreach (['documento', 'telefono', 'direccion', 'licencia_conduccion', 'user_id', 'motivo_retiro'] as $field) {
            $this->assertArrayNotHasKey($field, $employee);
        }
    }

    public function test_employee_detail_never_serializes_fcm_or_permission_internals(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $linkedUser = User::factory()->create([
            'role' => 'operador',
            'permisos' => ['inventario.write'],
            'fcm_token' => 'sensitive-device-token',
            'fcm_token_updated_at' => now(),
        ]);
        $employee = Empleado::create([
            'nombres' => 'Persona',
            'apellidos' => 'Protegida',
            'documento' => 'PRIVATE-999',
            'user_id' => $linkedUser->id,
            'estado' => 'activo',
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/empleados/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('documento', 'PRIVATE-999');
        $userData = $response->json('user');

        $this->assertSame($linkedUser->id, $userData['id']);
        $this->assertSame($linkedUser->email, $userData['email']);
        foreach (['fcm_token', 'fcm_token_updated_at', 'permisos', 'permisos_efectivos'] as $field) {
            $this->assertArrayNotHasKey($field, $userData);
        }
    }

    public function test_nested_model_serialization_is_safe_by_default(): void
    {
        $user = User::factory()->create([
            'phone' => '3001234567',
            'license_number' => 'PRIVATE-LICENSE',
            'fcm_token' => 'PRIVATE-FCM',
            'permisos' => ['personal.write'],
        ]);
        $employee = Empleado::create([
            'nombres' => 'Nested',
            'documento' => 'PRIVATE-DOCUMENT',
            'telefono' => '3007654321',
            'direccion' => 'PRIVATE-ADDRESS',
            'user_id' => $user->id,
            'estado' => 'activo',
        ]);

        foreach (['email', 'phone', 'license_number', 'fcm_token', 'permisos', 'permisos_efectivos'] as $field) {
            $this->assertArrayNotHasKey($field, $user->toArray());
        }

        foreach (['documento', 'telefono', 'direccion', 'user_id', 'motivo_retiro'] as $field) {
            $this->assertArrayNotHasKey($field, $employee->toArray());
        }
    }

    public function test_offboarding_preserves_employee_and_user_but_revokes_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $worker = User::factory()->create([
            'role' => 'operador',
            'fcm_token' => 'device-token',
            'fcm_token_updated_at' => now(),
        ]);
        $tokenId = $worker->createToken('field-device')->accessToken->getKey();
        $employee = Empleado::create([
            'nombres' => 'Trabajador',
            'apellidos' => 'Retirado',
            'user_id' => $worker->id,
            'estado' => 'activo',
        ]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/empleados/{$employee->id}", [
            'motivo_retiro' => 'Finalización de contrato',
        ])->assertOk();

        $this->assertDatabaseHas('empleados', [
            'id' => $employee->id,
            'estado' => 'retirado',
            'motivo_retiro' => 'Finalización de contrato',
        ]);
        $this->assertDatabaseHas('users', ['id' => $worker->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

        $worker->refresh();
        $this->assertNull($worker->fcm_token);
        $this->assertNull($worker->fcm_token_updated_at);
    }

    public function test_retired_employee_cannot_log_in(): void
    {
        $worker = User::factory()->create([
            'email' => 'retired@semanur.com',
            'role' => 'operador',
        ]);
        Empleado::create([
            'nombres' => 'Retired',
            'user_id' => $worker->id,
            'estado' => 'retirado',
        ]);

        $this->postJson('/api/login', [
            'email' => 'retired@semanur.com',
            'password' => 'password',
            'device_name' => 'phpunit-device',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_case_variants_share_the_same_login_rate_limit(): void
    {
        User::factory()->create(['email' => 'rate-limit@semanur.com']);
        $variants = [
            'RATE-LIMIT@SEMANUR.COM',
            'Rate-Limit@Semanur.com',
            'rate-limit@semanur.com',
            'RATE-limit@semanur.COM',
            'rate-LIMIT@SEMANUR.com',
        ];

        foreach ($variants as $email) {
            $this->postJson('/api/login', [
                'email' => $email,
                'password' => 'wrong-password',
                'device_name' => 'phpunit-device',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/login', [
            'email' => 'Rate-Limit@Semanur.Com',
            'password' => 'wrong-password',
            'device_name' => 'phpunit-device',
        ])->assertTooManyRequests();
    }

    public function test_fcm_token_can_be_registered_cleared_and_is_cleared_on_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phpunit-device')->plainTextToken;

        $this->withToken($token)->postJson('/api/user/fcm-token', [
            'fcm_token' => 'registered-token',
        ])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'fcm_token' => 'registered-token']);

        $this->withToken($token)->postJson('/api/user/fcm-token', [
            'fcm_token' => null,
        ])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'fcm_token' => null]);

        $user->forceFill(['fcm_token' => 'registered-again', 'fcm_token_updated_at' => now()])->save();
        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $user->refresh();
        $this->assertNull($user->fcm_token);
        $this->assertNull($user->fcm_token_updated_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unhandled_api_errors_are_generic_even_when_debug_is_enabled(): void
    {
        $request = Request::create('/api/security-test', 'GET', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = app(ExceptionHandler::class)->render(
            $request,
            new RuntimeException('sensitive SQL constraint and filesystem path'),
        );
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('No fue posible procesar la solicitud.', $payload['message']);
        $this->assertTrue(Str::isUuid($payload['error_id']));
        $this->assertStringNotContainsString('sensitive', (string) $response->getContent());
    }
}
