<?php

namespace Tests\Feature\InventoryOrders;

use App\Models\PedidoOperacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\InventoryOrders\Concerns\PedidoDesfaseFixtures;
use Tests\TestCase;

/**
 * Recuperación de operaciones idempotentes por actor+pedido (plan sección 17)
 * y última recepción confirmada en el detalle del pedido.
 *
 * Tras perder una respuesta, la UI consulta
 * GET /{order}/operations/{command}/{operation_uuid} antes de ofrecer repetir;
 * el comando se valida contra allowlist y la operación contra actor+pedido.
 */
class PedidoOperacionRecoveryTest extends TestCase
{
    use PedidoDesfaseFixtures, RefreshDatabase;

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

    public function test_recupera_la_operacion_propia_por_comando_y_uuid(): void
    {
        $creador = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($creador);
        $operationId = (string) Str::uuid();

        $this->publicarPedido($pedido, $creador, ['client_operation_id' => $operationId])
            ->assertOk()
            ->assertJsonPath('data.estado', 'pendiente_recepcion');

        $respuesta = $this->recuperar($creador, $pedido, 'publicar_pedido', $operationId)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('operacion.command', 'publicar_pedido')
            ->assertJsonPath('operacion.client_operation_id', $operationId);

        $this->assertSame($pedido->fresh()->numero, $respuesta->json('data.numero'));
        $this->assertSame((string) $pedido->uuid, $respuesta->json('data.uuid'));
    }

    public function test_operacion_de_otro_actor_pedido_o_comando_desconocido_es_404(): void
    {
        $creador = $this->usuario('contabilidad');
        $otro = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($creador);
        $operationId = (string) Str::uuid();

        $this->publicarPedido($pedido, $creador, ['client_operation_id' => $operationId])->assertOk();

        // Otro actor (aunque tenga pedidos.read) no recupera el replay ajeno.
        $this->recuperar($otro, $pedido, 'publicar_pedido', $operationId)->assertStatus(404);

        // Otro pedido del mismo actor no puede leer una operación ajena.
        $otroPedido = $this->pedidoPublicable($creador);
        $this->recuperar($creador, $otroPedido, 'publicar_pedido', $operationId)->assertStatus(404);

        // Comando fuera de la allowlist.
        $this->recuperar($creador, $pedido, 'borrar_pedido', $operationId)->assertStatus(404);

        // UUID malformado o inexistente.
        $this->recuperar($creador, $pedido, 'publicar_pedido', 'no-es-uuid')->assertStatus(404);
        $this->recuperar($creador, $pedido, 'publicar_pedido', (string) Str::uuid())->assertStatus(404);
    }

    public function test_sin_capacidad_de_lectura_la_recuperacion_es_403(): void
    {
        $creador = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($creador);
        $operationId = (string) Str::uuid();

        $this->publicarPedido($pedido, $creador, ['client_operation_id' => $operationId])->assertOk();

        $this->recuperar($this->usuario('operativo'), $pedido, 'publicar_pedido', $operationId)
            ->assertStatus(403);
    }

    public function test_detalle_expone_la_ultima_recepcion_confirmada(): void
    {
        $creador = $this->usuario('contabilidad');

        [$pedido, $receptor, , , $recepcionId] = $this->pedidoConNovedadAbierta(creador: $creador);

        Sanctum::actingAs($creador);
        $this->getJson("/api/web/inventory-orders/{$pedido->uuid}")
            ->assertOk()
            ->assertJsonPath('data.ultima_recepcion_confirmada.recepcion_id', $recepcionId)
            ->assertJsonPath('data.ultima_recepcion_confirmada.estado', 'confirmada')
            ->assertJsonPath('data.ultima_recepcion_confirmada.receptor_empleado_id', fn ($v) => $v !== null);

        // Antes de confirmar, el detalle la expone como null.
        $borrador = $this->pedido($this->bodega(), $creador);

        Sanctum::actingAs($creador);
        $this->getJson("/api/web/inventory-orders/{$borrador->uuid}")
            ->assertOk()
            ->assertJsonPath('data.ultima_recepcion_confirmada', null);

        $this->assertNotNull($receptor);

        $operacion = PedidoOperacion::query()
            ->where('referencia', $pedido->uuid)
            ->where('command', 'confirmar_recepcion')
            ->firstOrFail();

        $this->assertSame($recepcionId, (int) ($operacion->resultado['receipt_id'] ?? 0));
    }

    private function recuperar(
        \App\Models\User $actor,
        \App\Models\PedidoInventario $pedido,
        string $command,
        string $operationId,
    ): \Illuminate\Testing\TestResponse {
        Sanctum::actingAs($actor);

        return $this->getJson(
            "/api/web/inventory-orders/{$pedido->uuid}/operations/{$command}/{$operationId}",
        );
    }
}
