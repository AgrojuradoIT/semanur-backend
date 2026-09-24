<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\PedidoEstado;
use App\Domain\InventoryOrders\RecepcionEstado;
use App\Models\PedidoAlerta;
use App\Models\PedidoDocumento;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoRecepcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\InventoryOrders\Concerns\PedidoPublicacionFixtures;
use Tests\TestCase;

/**
 * Retiro documental y cancelación U5 (plan secciones 6 y 11; R08/R15):
 * guardas de recepción confirmada y ledger, alertas a destinatarios previos y
 * conservación total de evidencia.
 */
class PedidoRetiroCancelacionTest extends TestCase
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

    public function test_retiro_devuelve_a_borrador_descongela_el_esperado_y_avisa_a_los_destinatarios(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $this->receptorHabilitado();

        $pedido = $this->pedidoPublicable($actor);
        $this->publicarPedido($pedido, $actor)->assertOk();

        Sanctum::actingAs($actor);

        $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/return-for-correction", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 2,
            'motivo' => 'Factura equivocada: requiere nueva revisión',
        ])->assertOk()
            ->assertJsonPath('data.estado', PedidoEstado::BORRADOR)
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.motivo', 'Factura equivocada: requiere nueva revisión');

        $fresco = $pedido->fresh();

        $this->assertSame(PedidoEstado::BORRADOR, $fresco->estado);
        $this->assertSame(3, $fresco->versionActual());
        $this->assertNotNull($fresco->published_at, 'La evidencia de publicación previa se conserva.');
        $this->assertSame(
            0,
            PedidoInventarioLinea::query()->where('pedido_id', $pedido->pedido_id)->whereNotNull('esperado_congelado_at')->count(),
        );

        $evento = PedidoEvento::query()->where('event_type', 'pedido_retirado')->firstOrFail();
        $this->assertSame('Factura equivocada: requiere nueva revisión', $evento->datos_despues['motivo']);
        $this->assertSame(PedidoEstado::PENDIENTE_RECEPCION, $evento->datos_antes['estado']);

        $this->assertSame(2, PedidoAlerta::query()->where('tipo', 'pedido_retirado')->count());
        $this->assertSame(4, PedidoAlerta::query()->where('pedido_id', $pedido->pedido_id)->count());
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_retiro_bloqueado_si_existe_recepcion_confirmada(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($actor);

        $this->publicarPedido($pedido, $actor)->assertOk();

        $recepcion = PedidoRecepcion::create(['pedido_id' => $pedido->pedido_id]);
        $recepcion->transicionarA(RecepcionEstado::CONFIRMADA, ['confirmada_at' => now()]);

        Sanctum::actingAs($actor);

        $respuesta = $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/return-for-correction", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 2,
            'motivo' => 'Intento con recepción confirmada',
        ])->assertStatus(409);

        $this->assertStringContainsString('recepción confirmada', (string) $respuesta->json('message'));
        $this->assertSame(PedidoEstado::PENDIENTE_RECEPCION, $pedido->fresh()->estado);
    }

    public function test_retiro_bloqueado_por_movimientos_de_ledger(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($actor);

        $this->publicarPedido($pedido, $actor)->assertOk();

        $this->insertarMovimiento(PedidoInventarioLinea::query()->firstOrFail());

        Sanctum::actingAs($actor);

        $respuesta = $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/return-for-correction", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 2,
            'motivo' => 'Intento con ledger emitido',
        ])->assertStatus(409);

        $this->assertStringContainsString('movimientos de inventario', (string) $respuesta->json('message'));
        $this->assertSame(PedidoEstado::PENDIENTE_RECEPCION, $pedido->fresh()->estado);
    }

    public function test_retiro_solo_desde_pendiente_recepcion_con_mensaje_propio(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($actor);

        Sanctum::actingAs($actor);

        $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/return-for-correction", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 1,
            'motivo' => 'Pedido todavía en borrador',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Solo se puede retirar a corrección un pedido pendiente de recepción.');
    }

    public function test_retiro_requiere_capacidad_de_publicacion(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($actor);

        $this->publicarPedido($pedido, $actor)->assertOk();

        Sanctum::actingAs($this->usuario('auxiliar_bodega'));

        $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/return-for-correction", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 2,
            'motivo' => 'Sin capacidad',
        ])->assertStatus(403);
    }

    public function test_cancelacion_desde_borrador_no_genera_alertas_y_conserva_todo(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedidoPublicable($actor);

        Sanctum::actingAs($actor);

        $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/cancel", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 1,
            'motivo' => 'Compra anulada antes de publicar',
        ])->assertOk()
            ->assertJsonPath('data.estado', PedidoEstado::CANCELADO)
            ->assertJsonPath('data.destinatarios_notificados', 0);

        $fresco = $pedido->fresh();

        $this->assertSame(PedidoEstado::CANCELADO, $fresco->estado);
        $this->assertNotNull($fresco->cancelled_at);
        $this->assertSame('Compra anulada antes de publicar', $fresco->cancel_motivo);

        $this->assertSame(0, PedidoAlerta::query()->count());
        $this->assertSame(1, PedidoInventario::query()->count());
        $this->assertSame(2, PedidoDocumento::query()->where('pedido_id', $pedido->pedido_id)->count());
        $this->assertSame(1, PedidoEvento::query()->where('event_type', 'pedido_cancelado')->count());
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_cancelacion_tras_publicacion_avisa_a_los_receptores_previos(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($actor);

        $this->publicarPedido($pedido, $actor)->assertOk();

        Sanctum::actingAs($actor);

        $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/cancel", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 2,
            'motivo' => 'Proveedor canceló la compra',
        ])->assertOk();

        $this->assertSame(2, PedidoAlerta::query()->where('tipo', 'pedido_cancelado')->count());
        $this->assertSame(4, PedidoAlerta::query()->where('pedido_id', $pedido->pedido_id)->count());

        $evento = PedidoEvento::query()->where('event_type', 'pedido_cancelado')->firstOrFail();
        $this->assertTrue($evento->datos_despues['publicado_antes']);
    }

    public function test_cancelacion_bloqueada_por_movimientos_de_ledger(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedidoPublicable($actor);

        $this->insertarMovimiento(PedidoInventarioLinea::query()->firstOrFail());

        Sanctum::actingAs($actor);

        $respuesta = $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/cancel", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 1,
            'motivo' => 'Intento con ledger emitido',
        ])->assertStatus(409);

        $this->assertStringContainsString('movimientos de inventario', (string) $respuesta->json('message'));
        $this->assertSame(PedidoEstado::BORRADOR, $pedido->fresh()->estado);
        $this->assertSame(1, DB::table('transaccion_inventarios')->count());
    }

    public function test_cancelacion_desde_en_validacion_es_permitida(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedidoPublicable($actor);

        $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION);
        $pedido->transicionarA(PedidoEstado::EN_VALIDACION);

        Sanctum::actingAs($actor);

        $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/cancel", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 3,
            'motivo' => 'Se cancela durante validación',
        ])->assertOk();

        $this->assertSame(PedidoEstado::CANCELADO, $pedido->fresh()->estado);
    }

    public function test_version_desactualizada_en_retiro_y_cancelacion(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($actor);

        $this->publicarPedido($pedido, $actor)->assertOk();

        Sanctum::actingAs($actor);

        $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/return-for-correction", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 99,
            'motivo' => 'Versión vieja',
        ])->assertStatus(409)->assertJsonPath('current_version', 2);

        $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/cancel", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 99,
            'motivo' => 'Versión vieja',
        ])->assertStatus(409)->assertJsonPath('current_version', 2);
    }

    private function insertarMovimiento(PedidoInventarioLinea $linea): void
    {
        DB::table('transaccion_inventarios')->insert([
            'producto_id' => $this->producto()->producto_id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => '4.00',
            'transaccion_motivo' => 'compra',
            'pedido_linea_id' => $linea->linea_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
