<?php

namespace Tests\Feature\InventoryOrders;

use App\Models\PedidoAlerta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\InventoryOrders\Concerns\PedidoPublicacionFixtures;
use Tests\TestCase;

/**
 * Bandeja Web de alertas U5: solo propias, filtro de no leídas, contador en
 * meta, marcado idempotente y revalidación de capacidad/estado laboral.
 */
class PedidoAlertaApiTest extends TestCase
{
    use PedidoPublicacionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inventory_orders.enabled' => true]);

        $this->withHeaders(['Accept' => 'application/json']);
        $this->limpiarDocumentosOcr();
    }

    protected function tearDown(): void
    {
        $this->limpiarDocumentosOcr();

        parent::tearDown();
    }

    public function test_lista_solo_alertas_propias_con_meta_de_no_leidas(): void
    {
        $actor = $this->usuario('contabilidad');
        $receptorA = $this->receptorHabilitado();
        $receptorB = $this->receptorHabilitado();

        $pedido = $this->pedidoPublicable($actor);
        $this->publicarPedido($pedido, $actor)->assertOk();

        Sanctum::actingAs($receptorA);

        $this->getJson('/api/web/inventory-order-alerts')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipo', 'pedido_publicado')
            ->assertJsonPath('data.0.leida', false)
            ->assertJsonPath('data.0.pedido.uuid', $pedido->uuid)
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('meta.total', 1);

        Sanctum::actingAs($receptorB);

        $this->getJson('/api/web/inventory-order-alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.unread_count', 1);
    }

    public function test_filtros_unread_leida_tipo_paginacion_y_contador(): void
    {
        $actor = $this->usuario('contabilidad');
        $receptor = $this->receptorHabilitado();

        $this->publicarPedido($this->pedidoPublicable($actor), $actor)->assertOk();
        $this->publicarPedido($this->pedidoPublicable($actor), $actor)->assertOk();

        Sanctum::actingAs($receptor);

        $this->getJson('/api/web/inventory-order-alerts?leida=0')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.unread_count', 2);

        $alertaId = (int) PedidoAlerta::query()
            ->where('destinatario_user_id', $receptor->id)
            ->orderBy('alerta_id')
            ->value('alerta_id');

        $this->postJson("/api/web/inventory-order-alerts/{$alertaId}/read")->assertOk();

        $this->getJson('/api/web/inventory-order-alerts?leida=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.unread_count', 1);

        $this->getJson('/api/web/inventory-order-alerts?leida=0')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/web/inventory-order-alerts?tipo=novedad_abierta')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/web/inventory-order-alerts?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_marcar_leida_es_idempotente_y_la_alerta_ajena_es_404(): void
    {
        $actor = $this->usuario('contabilidad');
        $receptorA = $this->receptorHabilitado();
        $receptorB = $this->receptorHabilitado();

        $this->publicarPedido($this->pedidoPublicable($actor), $actor)->assertOk();

        $alertaA = PedidoAlerta::query()->where('destinatario_user_id', $receptorA->id)->firstOrFail();
        $alertaB = PedidoAlerta::query()->where('destinatario_user_id', $receptorB->id)->firstOrFail();

        Sanctum::actingAs($receptorA);

        $this->postJson("/api/web/inventory-order-alerts/{$alertaA->alerta_id}/read")
            ->assertOk()
            ->assertJsonPath('data.leida', true);

        $this->assertNotNull($alertaA->fresh()->leida_at);

        // Idempotente: no cambia la fecha original.
        $fechaLectura = $alertaA->fresh()->leida_at->toISOString();

        $this->postJson("/api/web/inventory-order-alerts/{$alertaA->alerta_id}/read")->assertOk();

        $this->assertSame($fechaLectura, $alertaA->fresh()->leida_at->toISOString());

        // Alerta de otro usuario: 404 sin revelar existencia.
        $this->postJson("/api/web/inventory-order-alerts/{$alertaB->alerta_id}/read")->assertStatus(404);

        $this->assertNull($alertaB->fresh()->leida_at);
    }

    public function test_capacidad_revalidada_al_leer(): void
    {
        $actor = $this->usuario('contabilidad');
        $receptor = $this->receptorHabilitado();
        $this->publicarPedido($this->pedidoPublicable($actor), $actor)->assertOk();

        $alerta = PedidoAlerta::query()->where('destinatario_user_id', $receptor->id)->firstOrFail();

        $receptor->role = 'mecanico';
        $receptor->save();

        Sanctum::actingAs($receptor->fresh());

        $this->getJson('/api/web/inventory-order-alerts')->assertStatus(403);
        $this->postJson("/api/web/inventory-order-alerts/{$alerta->alerta_id}/read")->assertStatus(403);
        $this->assertNull($alerta->fresh()->leida_at);
    }

    public function test_usuario_retirado_pierde_acceso(): void
    {
        $actor = $this->usuario('contabilidad');
        $receptor = $this->receptorHabilitado();
        $this->publicarPedido($this->pedidoPublicable($actor), $actor)->assertOk();

        $this->empleado(['estado' => 'retirado', 'user_id' => $receptor->id]);

        Sanctum::actingAs($receptor->fresh());

        $this->getJson('/api/web/inventory-order-alerts')->assertStatus(403);
    }

    public function test_flag_apagado_oculta_la_bandeja_y_sin_token_es_401(): void
    {
        config(['inventory_orders.enabled' => false]);

        $this->getJson('/api/web/inventory-order-alerts')->assertStatus(404);
        $this->postJson('/api/web/inventory-order-alerts/1/read')->assertStatus(404);

        config(['inventory_orders.enabled' => true]);

        $this->getJson('/api/web/inventory-order-alerts')->assertStatus(401);
        $this->postJson('/api/web/inventory-order-alerts/1/read')->assertStatus(401);
    }
}
