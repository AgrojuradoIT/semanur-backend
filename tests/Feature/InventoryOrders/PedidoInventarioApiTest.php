<?php

namespace Tests\Feature\InventoryOrders;

use App\DTO\InventoryOrders\DecimalQuantity;
use App\Models\Bodega;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoOperacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidoInventarioApiTest extends TestCase
{
    use RefreshDatabase;

    private Bodega $bodega;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inventory_orders.enabled' => true]);

        $this->bodega = Bodega::create(['nombre' => 'Bodega U2', 'tipo' => 'estandar']);
    }

    // ─── Flag y autenticación ─────────────────────────────

    public function test_flag_off_hides_every_endpoint_with_404_even_unauthenticated(): void
    {
        config(['inventory_orders.enabled' => false]);
        $uuid = (string) Str::uuid();

        $this->getJson('/api/web/inventory-orders')->assertStatus(404);
        $this->postJson('/api/web/inventory-orders', [])->assertStatus(404);
        $this->getJson("/api/web/inventory-orders/{$uuid}")->assertStatus(404);
        $this->patchJson("/api/web/inventory-orders/{$uuid}", [])->assertStatus(404);
    }

    public function test_enabled_group_requires_authentication(): void
    {
        $this->getJson('/api/web/inventory-orders')->assertStatus(401);
        $this->postJson('/api/web/inventory-orders', [])->assertStatus(401);
    }

    public function test_role_without_assignment_cannot_read_or_create(): void
    {
        $mecanico = User::factory()->create(['role' => 'mecanico']);
        Sanctum::actingAs($mecanico);

        $this->getJson('/api/web/inventory-orders')->assertStatus(403);
        $this->postJson('/api/web/inventory-orders', $this->payload())->assertStatus(403);
    }

    public function test_auxiliar_bodega_can_read_but_not_create_or_edit_drafts(): void
    {
        $auxiliar = User::factory()->create(['role' => 'auxiliar_bodega']);
        Sanctum::actingAs($auxiliar);

        $this->getJson('/api/web/inventory-orders')->assertOk();

        $pedido = $this->crearComoContabilidad();
        Sanctum::actingAs($auxiliar);

        $this->getJson("/api/web/inventory-orders/{$pedido['uuid']}")->assertOk();
        $this->postJson('/api/web/inventory-orders', $this->payload())->assertStatus(403);
        $this->patchJson("/api/web/inventory-orders/{$pedido['uuid']}", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 1,
            'proveedor_nombre_raw' => 'Otro',
        ])->assertStatus(403);
    }

    // ─── Creación idempotente ─────────────────────────────

    public function test_create_draft_is_idempotent_per_client_operation(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'contabilidad']));

        $payload = $this->payload();
        $first = $this->postJson('/api/web/inventory-orders', $payload)->assertStatus(201);

        $first->assertJsonPath('success', true)
            ->assertJsonPath('idempotent_replay', false)
            ->assertJsonPath('data.lineas.0.cantidad_esperada', '4.00')
            ->assertJsonPath('data.lineas.1.cantidad_esperada', '4.00')
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.acciones.editar', true)
            ->assertJsonPath('data.acciones.publicar', true);

        $uuid = $first->json('data.uuid');
        $this->assertNotNull($uuid);

        $replay = $this->postJson('/api/web/inventory-orders', $payload)->assertOk();
        $replay->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('data.uuid', $uuid);

        $this->assertDatabaseCount('pedidos_inventario', 1);
        $this->assertDatabaseCount('pedido_inventario_lineas', 2);
        $this->assertDatabaseCount('pedido_operaciones', 1);
        $this->assertDatabaseCount('pedido_eventos', 1);
    }

    public function test_same_client_operation_with_different_payload_is_a_conflict(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'contabilidad']));

        $payload = $this->payload();
        $this->postJson('/api/web/inventory-orders', $payload)->assertStatus(201);

        $payload['lineas'][0]['cantidad_esperada'] = '5.00';

        $this->postJson('/api/web/inventory-orders', $payload)
            ->assertStatus(409)
            ->assertJsonPath('message', 'client_operation_id ya fue utilizado con un pedido diferente.');

        $this->assertDatabaseCount('pedidos_inventario', 1);
        $this->assertDatabaseCount('pedido_inventario_lineas', 2);
        $this->assertSame(
            '4.00',
            DecimalQuantity::normalize((string) DB::table('pedido_inventario_lineas')->where('orden', 1)->value('cantidad_esperada')),
        );
    }

    // ─── Edición con versión optimista ────────────────────

    public function test_patch_edits_draft_with_version_and_rejects_stale_versions(): void
    {
        $creado = $this->crearComoContabilidad();
        $operationId = (string) Str::uuid();

        $patch = $this->patchJson("/api/web/inventory-orders/{$creado['uuid']}", [
            'client_operation_id' => $operationId,
            'version' => 1,
            'proveedor_nombre_raw' => 'Proveedor Corregido',
            'lineas' => [
                ['orden' => 1, 'sku_raw' => 'TO000057', 'descripcion_raw' => 'TORNILLO', 'cantidad_esperada' => '6.00'],
                ['orden' => 3, 'sku_raw' => 'NEW-3', 'descripcion_raw' => 'Nuevo', 'cantidad_esperada' => '2'],
            ],
        ])->assertOk();

        $patch->assertJsonPath('idempotent_replay', false)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.proveedor_nombre_raw', 'Proveedor Corregido')
            ->assertJsonCount(2, 'data.lineas');

        $this->assertSame(
            ['1', '3'],
            DB::table('pedido_inventario_lineas')->orderBy('orden')->pluck('orden')->map(fn ($orden) => (string) $orden)->all(),
        );
        $this->assertSame(
            '6.00',
            DecimalQuantity::normalize((string) DB::table('pedido_inventario_lineas')->where('orden', 1)->value('cantidad_esperada')),
        );

        $replay = $this->patchJson("/api/web/inventory-orders/{$creado['uuid']}", [
            'client_operation_id' => $operationId,
            'version' => 1,
            'proveedor_nombre_raw' => 'Proveedor Corregido',
            'lineas' => [
                ['orden' => 1, 'sku_raw' => 'TO000057', 'descripcion_raw' => 'TORNILLO', 'cantidad_esperada' => '6.00'],
                ['orden' => 3, 'sku_raw' => 'NEW-3', 'descripcion_raw' => 'Nuevo', 'cantidad_esperada' => '2'],
            ],
        ])->assertOk();
        $replay->assertJsonPath('idempotent_replay', true);

        $conflict = $this->patchJson("/api/web/inventory-orders/{$creado['uuid']}", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 1,
            'proveedor_nombre_raw' => 'Tarde',
        ])->assertStatus(409);

        $conflict->assertJsonPath('current_version', 2);
        $this->assertSame(1, PedidoInventario::query()->count());
    }

    public function test_state_cannot_be_set_through_patch(): void
    {
        $creado = $this->crearComoContabilidad();

        $this->patchJson("/api/web/inventory-orders/{$creado['uuid']}", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 1,
            'estado' => 'pendiente_recepcion',
        ])->assertStatus(422)->assertJsonValidationErrors('estado');
    }

    // ─── Validaciones ─────────────────────────────────────

    public function test_invalid_decimal_quantities_are_rejected_by_the_u1_dto(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'contabilidad']));

        foreach (['4.001', '1e3', '1E3', '-1.00', '0.00', '1,234', 4] as $invalida) {
            $payload = $this->payload();
            $payload['lineas'][0]['cantidad_esperada'] = $invalida;

            $this->postJson('/api/web/inventory-orders', $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors('lineas.0.cantidad_esperada');
        }

        $this->assertDatabaseCount('pedidos_inventario', 0);
    }

    public function test_nonexistent_or_missing_bodega_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'contabilidad']));

        $sinBodega = $this->payload();
        unset($sinBodega['bodega_id']);
        $this->postJson('/api/web/inventory-orders', $sinBodega)
            ->assertStatus(422)
            ->assertJsonValidationErrors('bodega_id');

        $bodegaInexistente = $this->payload(['bodega_id' => 999999]);
        $this->postJson('/api/web/inventory-orders', $bodegaInexistente)
            ->assertStatus(422)
            ->assertJsonValidationErrors('bodega_id');
    }

    // ─── Listado y detalle ────────────────────────────────

    public function test_index_filters_and_detail_are_available_to_reading_roles(): void
    {
        $primero = $this->crearComoContabilidad(['proveedor_nombre_raw' => 'Alfa']);
        $this->crearComoContabilidad(['proveedor_nombre_raw' => 'Beta']);

        Sanctum::actingAs(User::factory()->create(['role' => 'jefe_taller']));

        $this->getJson('/api/web/inventory-orders?proveedor=Alfa&estado=borrador&per_page=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.proveedor_nombre_raw', 'Alfa')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonMissingPath('data.0.acciones');

        $this->getJson("/api/web/inventory-orders/{$primero['uuid']}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $primero['uuid'])
            ->assertJsonCount(2, 'data.lineas')
            ->assertJsonPath('data.acciones.editar', false)
            ->assertJsonPath('data.acciones.cancelar', false);
    }

    public function test_unknown_order_uuid_is_not_found(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'contabilidad']));

        $this->getJson('/api/web/inventory-orders/'.Str::uuid())->assertStatus(404);
    }

    // ─── Auditoría append-only ────────────────────────────

    public function test_create_and_update_write_append_only_audit_events(): void
    {
        Sanctum::actingAs($contabilidad = User::factory()->create(['role' => 'contabilidad']));

        $createOperation = (string) Str::uuid();
        $creado = $this->postJson('/api/web/inventory-orders', $this->payload([
            'client_operation_id' => $createOperation,
        ]))->assertStatus(201);

        $updateOperation = (string) Str::uuid();
        $this->patchJson("/api/web/inventory-orders/{$creado->json('data.uuid')}", [
            'client_operation_id' => $updateOperation,
            'version' => 1,
            'proveedor_nombre_raw' => 'Ajustado',
        ])->assertOk();

        $this->assertDatabaseCount('pedido_eventos', 2);
        $this->assertDatabaseHas('pedido_eventos', [
            'event_type' => 'borrador_creado',
            'actor_user_id' => $contabilidad->id,
            'actor_rol_snapshot' => 'contabilidad',
            'command_id' => $createOperation,
        ]);
        $this->assertDatabaseHas('pedido_eventos', [
            'event_type' => 'borrador_actualizado',
            'actor_user_id' => $contabilidad->id,
            'command_id' => $updateOperation,
        ]);

        $creadoEvento = PedidoEvento::query()->where('event_type', 'borrador_creado')->firstOrFail();
        $this->assertNull($creadoEvento->datos_antes);

        $actualizado = PedidoEvento::query()->where('event_type', 'borrador_actualizado')->firstOrFail();
        $this->assertSame('Proveedor Oscar', $actualizado->datos_antes['proveedor_nombre_raw'] ?? null);
        $this->assertSame('Ajustado', $actualizado->datos_despues['proveedor_nombre_raw'] ?? null);

        $this->assertSame(2, PedidoOperacion::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'client_operation_id' => (string) Str::uuid(),
            'proveedor_nombre_raw' => 'Proveedor Oscar',
            'referencia_factura' => 'FV-001',
            'bodega_id' => $this->bodega->bodega_id,
            'lineas' => [
                [
                    'orden' => 1,
                    'sku_raw' => 'TO000057',
                    'descripcion_raw' => 'TORNILLO CORTAMALEZA',
                    'cantidad_esperada' => '4.00',
                ],
                [
                    'orden' => 2,
                    'sku_raw' => null,
                    'descripcion_raw' => 'FILTRO',
                    'cantidad_esperada' => '4',
                ],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function crearComoContabilidad(array $overrides = []): array
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'contabilidad']));

        return $this->postJson('/api/web/inventory-orders', $this->payload($overrides))
            ->assertStatus(201)
            ->json('data');
    }
}
