<?php

namespace Tests\Feature\InventoryOrders;

use App\Jobs\ProcesarPedidoOcr;
use App\Services\Inventory\Orders\Ocr\FakePedidoOcrProvider;
use App\Services\Inventory\Orders\Ocr\OcrException;
use App\Services\Inventory\Orders\PedidoOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\InventoryOrders\Concerns\PedidoFixtures;
use Tests\Feature\InventoryOrders\Concerns\PedidoOcrFixtures;
use Tests\TestCase;

/**
 * Job ProcesarPedidoOcr (U4): timeout/tries desde config, backoff con jitter,
 * idempotencia y agotamiento de la cola.
 */
class PedidoOcrJobTest extends TestCase
{
    use PedidoFixtures, PedidoOcrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inventory_orders.enabled' => true,
            'inventory_orders.ocr.provider' => 'fake',
            'inventory_orders.ocr.max_intentos' => 3,
        ]);

        $this->limpiarDocumentosOcr();
    }

    protected function tearDown(): void
    {
        $this->limpiarDocumentosOcr();

        parent::tearDown();
    }

    public function test_handle_procesa_el_intento_y_es_idempotente(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);

        $this->usarProveedor(new FakePedidoOcrProvider);
        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        $job = new ProcesarPedidoOcr((int) $intento->intento_id);
        $job->handle($service);

        $intento->refresh();
        $this->assertSame('completado', $intento->estado);
        $this->assertSame(1, $intento->intentos);

        // Reentrega del mismo job: no-op, un único resultado vigente.
        $job->handle($service);

        $intento->refresh();
        $this->assertSame('completado', $intento->estado);
        $this->assertSame(1, $intento->intentos);

        $this->assertDatabaseCount('pedido_ocr_intentos', 1);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_handle_de_intento_inexistente_es_no_op(): void
    {
        $job = new ProcesarPedidoOcr(999999);
        $job->handle(app(PedidoOcrService::class));

        $this->assertDatabaseCount('pedido_ocr_intentos', 0);
    }

    public function test_handle_lanza_excepcion_para_reintento_tras_error_transitorio(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);

        $this->usarProveedor($this->proveedorFake(function (): array {
            throw OcrException::reintentable('proveedor_timeout', 'timeout de prueba');
        }));

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        $job = new ProcesarPedidoOcr((int) $intento->intento_id);

        try {
            $job->handle($service);
            $this->fail('Un error reintentable debe relanzar para aplicar backoff.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('timeout', $exception->getMessage());
        }

        $intento->refresh();
        $this->assertSame('procesando', $intento->estado);
        $this->assertSame(1, $intento->intentos);
        $this->assertSame('proveedor_timeout', $intento->error_code);
        $this->assertNull($intento->lease_token);
    }

    public function test_failed_agota_el_intento_si_la_cola_se_queda_sin_reintentos(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);

        // Camino principal: el intento quedó procesando sin lease vivo.
        $this->usarProveedor($this->proveedorFake(function (): array {
            throw OcrException::reintentable('proveedor_timeout', 'timeout de prueba');
        }));

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);
        $service->procesar($intento);

        $job = new ProcesarPedidoOcr((int) $intento->intento_id);
        $job->failed(new RuntimeException('cola agotada'));

        $intento->refresh();
        $this->assertSame('fallido', $intento->estado);
        $this->assertSame('intentos_agotados', $intento->error_code);
        $this->assertNotNull($intento->finished_at);

        // Un intento terminal no vuelve a fallar por agotamiento.
        $job->failed(new RuntimeException('otra vez'));

        $intento->refresh();
        $this->assertSame('fallido', $intento->estado);
        $this->assertSame('intentos_agotados', $intento->error_code);
    }

    public function test_failed_sobre_pendiente_no_reclamado_lo_deja_terminal_sin_violar_la_maquina(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);
        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        // El job murió antes de reclamar (infraestructura). pendiente->fallido
        // no está autorizado por la máquina U1; el cierre terminal es cancelado.
        $job = new ProcesarPedidoOcr((int) $intento->intento_id);
        $job->failed(new RuntimeException('infraestructura caída'));

        $intento->refresh();
        $this->assertSame('cancelado', $intento->estado);
        $this->assertSame('intentos_agotados', $intento->error_code);
    }

    public function test_handle_con_lease_vivo_reintenta_en_lugar_de_confirmar_en_falso(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);
        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        // Otro worker tiene el lease vivo (redelivery tras muerte del worker
        // original: timeout 60, lease 120, redelivery a los 90).
        $intento->estado = 'procesando';
        $intento->lease_token = 'lease-otro-worker';
        $intento->lease_expires_at = now()->addSeconds(120);
        $intento->save();

        $job = new ProcesarPedidoOcr((int) $intento->intento_id);

        try {
            $job->handle($service);
            $this->fail('Un no-op por lease activo debe relanzar para reintentar, no confirmar en falso.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('lease activo', mb_strtolower($exception->getMessage()));
        }

        $intento->refresh();
        $this->assertSame('procesando', $intento->estado);
        $this->assertSame('lease-otro-worker', $intento->lease_token);
        $this->assertSame(0, $intento->intentos);
    }

    public function test_failed_con_lease_vivo_no_queda_en_procesando_silencioso(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);
        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        $intento->estado = 'procesando';
        $intento->lease_token = 'lease-vivo';
        $intento->lease_expires_at = now()->addSeconds(120);
        $intento->save();

        $job = new ProcesarPedidoOcr((int) $intento->intento_id);
        $job->failed(new RuntimeException('cola agotada'));

        $intento->refresh();
        $this->assertSame('fallido', $intento->estado);
        $this->assertSame('lease_vivo_al_agotar', $intento->error_code);
        $this->assertNotNull($intento->finished_at);
        $this->assertNull($intento->lease_token);
        $this->assertDatabaseHas('pedido_eventos', [
            'pedido_id' => $pedido->pedido_id,
            'event_type' => 'ocr_fallido',
        ]);
    }

    public function test_job_respeta_conexion_cola_timeout_y_tries_configurados(): void
    {
        config([
            'inventory_orders.ocr.queue_connection' => 'database',
            'inventory_orders.ocr.queue' => 'ocr',
            'inventory_orders.ocr.timeout' => 45,
            'inventory_orders.ocr.max_intentos' => 4,
        ]);

        $job = new ProcesarPedidoOcr(1);

        $this->assertSame('database', $job->connection);
        $this->assertSame('ocr', $job->queue);
        $this->assertSame(45, $job->timeout);
        $this->assertSame(4, $job->tries);
    }

    public function test_backoff_es_exponencial_con_jitter_y_acotado(): void
    {
        config([
            'inventory_orders.ocr.max_intentos' => 4,
            'inventory_orders.ocr.backoff_base_seconds' => 10,
            'inventory_orders.ocr.backoff_max_seconds' => 300,
        ]);

        $esperas = (new ProcesarPedidoOcr(1))->backoff();

        $this->assertCount(3, $esperas, 'tries=4 implica 3 esperas de reintento.');
        $this->assertGreaterThanOrEqual(10, $esperas[0]);
        $this->assertLessThanOrEqual(15, $esperas[0]);
        $this->assertGreaterThanOrEqual(20, $esperas[1]);
        $this->assertLessThanOrEqual(25, $esperas[1]);
        $this->assertGreaterThanOrEqual(40, $esperas[2]);
        $this->assertLessThanOrEqual(45, $esperas[2]);
    }
}
