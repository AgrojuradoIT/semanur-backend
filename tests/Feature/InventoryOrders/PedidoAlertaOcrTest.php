<?php

namespace Tests\Feature\InventoryOrders;

use App\Events\NotificationSent;
use App\Models\Notificacion;
use App\Models\PedidoAlerta;
use App\Models\PedidoEvento;
use App\Services\Inventory\Orders\Ocr\OcrException;
use App\Services\Inventory\Orders\PedidoOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\InventoryOrders\Concerns\PedidoPublicacionFixtures;
use Tests\TestCase;

/**
 * Alertas OCR U5 (plan sección 12): requiere_revision y ocr_fallido avisan al
 * autor del borrador habilitado, con validación de cuenta activa y sin tocar
 * el pipeline legacy de Notificacion/FCM.
 */
class PedidoAlertaOcrTest extends TestCase
{
    use PedidoPublicacionFixtures, RefreshDatabase;

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

    public function test_ocr_requiere_revision_crea_alerta_para_el_autor_del_borrador(): void
    {
        $autor = $this->usuario('contabilidad');
        $pedido = $this->pedido(null, $autor);
        $documento = $this->documentoOcr($pedido);

        $this->usarProveedor($this->proveedorFake(
            fn (): array => ['provider' => 'test', 'provider_version' => 'test-1', 'full_text' => '', 'pages' => []],
        ));

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $autor);

        $this->assertSame('requiere_revision', $service->procesar($intento)->resultado);

        $evento = PedidoEvento::query()->where('event_type', 'requiere_revision')->firstOrFail();
        $this->assertSame($pedido->pedido_id, (int) $evento->pedido_id);

        $alerta = PedidoAlerta::query()
            ->where('tipo', 'requiere_revision')
            ->where('destinatario_user_id', $autor->id)
            ->firstOrFail();

        $this->assertSame($pedido->pedido_id, (int) $alerta->pedido_id);
        $this->assertSame($evento->event_uuid, $alerta->event_uuid);
        $this->assertNull($alerta->leida_at);

        $this->assertSame(0, Notificacion::query()->count());
    }

    public function test_ocr_fallido_terminal_crea_alerta_sin_contaminar_legacy(): void
    {
        $autor = $this->usuario('contabilidad');
        $pedido = $this->pedido(null, $autor);
        $documento = $this->documentoOcr($pedido);

        $this->usarProveedor($this->proveedorFake(
            fn () => throw OcrException::terminal('proveedor_error_terminal', 'credencial inválida'),
        ));

        Event::fake([NotificationSent::class]);

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $autor);

        $this->assertSame('fallido', $service->procesar($intento)->resultado);

        $evento = PedidoEvento::query()->where('event_type', 'ocr_fallido')->firstOrFail();
        $this->assertSame('proveedor_error_terminal', $evento->datos_despues['error_code']);

        $this->assertSame(1, PedidoAlerta::query()->where('tipo', 'ocr_fallido')->count());
        $this->assertSame(0, Notificacion::query()->count());
        Event::assertNotDispatched(NotificationSent::class);

        Sanctum::actingAs($this->usuario('admin'));

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }

    public function test_autor_inactivo_no_recibe_alerta_pero_el_evento_queda_auditado(): void
    {
        $autor = $this->usuario('contabilidad');
        $this->empleado(['estado' => 'retirado', 'user_id' => $autor->id]);

        $pedido = $this->pedido(null, $autor);
        $documento = $this->documentoOcr($pedido);

        $this->usarProveedor($this->proveedorFake(
            fn (): array => ['provider' => 'test', 'provider_version' => 'test-1', 'full_text' => '', 'pages' => []],
        ));

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $autor);

        $this->assertSame('requiere_revision', $service->procesar($intento)->resultado);

        $this->assertSame(1, PedidoEvento::query()->where('event_type', 'requiere_revision')->count());
        $this->assertSame(0, PedidoAlerta::query()->where('tipo', 'requiere_revision')->count());
    }

    public function test_fallo_reintentable_solo_avisa_al_agotar_intentos_y_no_duplica(): void
    {
        config(['inventory_orders.ocr.max_intentos' => 2]);

        $autor = $this->usuario('contabilidad');
        $pedido = $this->pedido(null, $autor);
        $documento = $this->documentoOcr($pedido);

        $this->usarProveedor($this->proveedorFake(
            fn () => throw OcrException::reintentable('proveedor_timeout', 'timeout de prueba'),
        ));

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $autor);

        $this->assertSame('reintentar', $service->procesar($intento)->resultado);
        $this->assertSame(0, PedidoAlerta::query()->where('tipo', 'ocr_fallido')->count());

        $this->assertSame('fallido', $service->procesar($intento->fresh())->resultado);
        $this->assertSame(1, PedidoAlerta::query()->where('tipo', 'ocr_fallido')->count());

        // Un intento terminal repetido es no-op: la alerta no se duplica.
        $service->procesar($intento->fresh());
        $this->assertSame(1, PedidoAlerta::query()->where('tipo', 'ocr_fallido')->count());
    }
}
