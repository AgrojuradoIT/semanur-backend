<?php

namespace Tests\Feature\InventoryOrders;

use App\Jobs\ProcesarPedidoOcr;
use App\Models\PedidoEvento;
use App\Services\Inventory\Orders\PedidoEventDispatcher;
use App\Services\Inventory\Orders\PedidoOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\InventoryOrders\Concerns\PedidoFixtures;
use Tests\Feature\InventoryOrders\Concerns\PedidoOcrFixtures;
use Tests\TestCase;

/**
 * Scanner idempotente de la outbox (U4): recupera eventos pendientes, respeta
 * --limit y nunca duplica trabajo. Sin scheduler (U9).
 */
class PedidoOcrOutboxCommandTest extends TestCase
{
    use PedidoFixtures, PedidoOcrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inventory_orders.enabled' => true,
            'inventory_orders.ocr.provider' => 'fake',
        ]);

        Queue::fake();
        $this->limpiarDocumentosOcr();
    }

    protected function tearDown(): void
    {
        $this->limpiarDocumentosOcr();

        parent::tearDown();
    }

    public function test_scanner_despacha_pendientes_una_sola_vez(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);
        $service = app(PedidoOcrService::class);

        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        $this->artisan('inventory-orders:dispatch-events')->assertSuccessful();

        Queue::assertPushed(ProcesarPedidoOcr::class, 1);
        Queue::assertPushed(
            ProcesarPedidoOcr::class,
            fn (ProcesarPedidoOcr $job): bool => $job->intentoId === (int) $intento->intento_id,
        );

        $evento = PedidoEvento::query()->where('event_type', 'ocr_solicitado')->firstOrFail();
        $this->assertSame('despachado', $evento->estado_despacho);
        $this->assertSame(1, $evento->intentos_despacho);
        $this->assertNotNull($evento->despachado_at);

        // Corrida repetida: el evento ya no está pendiente, no se reencola.
        $this->artisan('inventory-orders:dispatch-events')->assertSuccessful();

        Queue::assertPushed(ProcesarPedidoOcr::class, 1);
        $this->assertDatabaseCount('pedido_ocr_intentos', 1);
    }

    public function test_scanner_respeta_el_limite_y_procesa_el_resto_en_otra_corrida(): void
    {
        $actor = $this->usuario('contabilidad');

        for ($i = 0; $i < 3; $i++) {
            $pedido = $this->pedido();
            $documento = $this->documentoOcr($pedido);
            app(PedidoOcrService::class)->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);
        }

        $this->artisan('inventory-orders:dispatch-events', ['--limit' => 2])->assertSuccessful();

        Queue::assertPushed(ProcesarPedidoOcr::class, 2);
        $this->assertSame(2, PedidoEvento::query()->where('estado_despacho', 'despachado')->count());
        $this->assertSame(1, PedidoEvento::query()->where('estado_despacho', 'pendiente')->count());

        $this->artisan('inventory-orders:dispatch-events')->assertSuccessful();

        Queue::assertPushed(ProcesarPedidoOcr::class, 3);
        $this->assertSame(0, PedidoEvento::query()->where('estado_despacho', 'pendiente')->count());
    }

    public function test_scanner_marca_fallido_un_evento_sin_intento_valido(): void
    {
        $pedido = $this->pedido();

        $evento = PedidoEvento::create([
            'pedido_id' => $pedido->pedido_id,
            'event_type' => 'ocr_solicitado',
            'datos_despues' => ['origen' => 'prueba'],
        ]);

        $this->artisan('inventory-orders:dispatch-events')->assertSuccessful();

        Queue::assertNotPushed(ProcesarPedidoOcr::class);

        $evento->refresh();
        $this->assertSame('fallido', $evento->estado_despacho);
        $this->assertSame(1, $evento->intentos_despacho);
        $this->assertDatabaseCount('pedido_ocr_intentos', 0);
    }

    public function test_scanner_rechaza_limite_invalido(): void
    {
        $this->artisan('inventory-orders:dispatch-events', ['--limit' => 0])->assertFailed();

        Queue::assertNotPushed(ProcesarPedidoOcr::class);
    }

    public function test_despachar_por_intento_no_duplica_si_ya_fue_despachado(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        $this->assertTrue(app(PedidoEventDispatcher::class)->despacharPorIntento($intento));
        $this->assertFalse(app(PedidoEventDispatcher::class)->despacharPorIntento($intento));

        Queue::assertPushed(ProcesarPedidoOcr::class, 1);
    }
}
