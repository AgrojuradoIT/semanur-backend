<?php

namespace Tests\Feature\InventoryOrders;

use App\Jobs\ProcesarPedidoOcr;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoOcrIntento;
use App\Services\Inventory\Orders\PedidoDocumentoService;
use App\Services\Inventory\Orders\PedidoOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\InventoryOrders\Concerns\PedidoDocumentoFixtures;
use Tests\Feature\InventoryOrders\Concerns\PedidoFixtures;
use Tests\Feature\InventoryOrders\Concerns\PedidoOcrFixtures;
use Tests\TestCase;

/**
 * Endpoints OCR (U4): upload siigo -> 202 + intento, factura -> 201, consulta
 * del intento, reproceso explícito con 409 y autorización. Cero stock.
 */
class PedidoOcrApiTest extends TestCase
{
    use PedidoDocumentoFixtures, PedidoFixtures, PedidoOcrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inventory_orders.enabled' => true,
            'inventory_orders.ocr.provider' => 'fake',
        ]);

        $this->withHeaders(['Accept' => 'application/json']);
        Queue::fake();
        $this->limpiarDocumentosOcr();
    }

    protected function tearDown(): void
    {
        $this->limpiarDocumentosOcr();

        parent::tearDown();
    }

    public function test_upload_siigo_responde_202_con_documento_intento_y_evento(): void
    {
        $actor = $this->usuario('contabilidad');
        Sanctum::actingAs($actor);
        $pedido = $this->pedido();

        $response = $this->post($this->urlDocumentos($pedido), [
            'rol' => PedidoDocumentoService::ROL_PANTALLAZO_SIIGO,
            'file' => $this->uploadJpeg(),
        ])->assertStatus(202);

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.documento.rol', 'pantallazo_siigo')
            ->assertJsonPath('data.intento.estado', 'pendiente')
            ->assertJsonPath('data.intento.proveedor', 'fake')
            ->assertJsonPath('data.intento.intentos', 0)
            ->assertJsonPath('data.intento.max_intentos', 3)
            ->assertJsonPath('data.intento.document_revision', 1)
            ->assertJsonPath('data.intento.lines', []);

        $this->assertDatabaseCount('pedido_documentos', 1);
        $this->assertDatabaseCount('pedido_ocr_intentos', 1);
        $this->assertDatabaseCount('pedido_eventos', 2);

        $evento = PedidoEvento::query()->where('event_type', 'ocr_solicitado')->firstOrFail();
        $this->assertSame('despachado', $evento->estado_despacho);
        $this->assertSame(1, $evento->intentos_despacho);
        $this->assertNotNull($evento->despachado_at);
        $this->assertSame('upload', $evento->datos_despues['origen']);

        Queue::assertPushed(ProcesarPedidoOcr::class, 1);
        $this->assertDatabaseCount('transaccion_inventarios', 0);

        $contenido = (string) $response->getContent();
        foreach (['clave_trabajo', 'lease_token', 'fencing_token', 'storage_key', 'pedidos/'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $contenido);
        }
    }

    public function test_upload_factura_conserva_201_sin_intento_ni_evento_ocr(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();

        $this->post($this->urlDocumentos($pedido), [
            'rol' => PedidoDocumentoService::ROL_FACTURA,
            'file' => $this->uploadJpeg(),
        ])->assertStatus(201)
            ->assertJsonPath('data.rol', 'factura');

        $this->assertDatabaseCount('pedido_documentos', 1);
        $this->assertDatabaseCount('pedido_ocr_intentos', 0);
        $this->assertDatabaseCount('pedido_eventos', 1);
        $this->assertSame(0, PedidoEvento::query()->where('event_type', 'ocr_solicitado')->count());

        Queue::assertNotPushed(ProcesarPedidoOcr::class);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_attempt_get_expone_el_contrato_sin_secretos(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();

        $intentoId = (int) $this->subir($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO)
            ->json('data.intento.id');

        app(PedidoOcrService::class)->procesar(PedidoOcrIntento::query()->findOrFail($intentoId));

        $response = $this->get($this->urlIntentos($pedido, $intentoId))->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.estado', 'completado')
            ->assertJsonPath('data.schema_version', 1)
            ->assertJsonPath('data.proveedor', 'fake')
            ->assertJsonPath('data.requires_human_revision', false)
            ->assertJsonPath('data.supplier.value', 'OSCAR DARÍO DÍAZ CASARUBIAS')
            ->assertJsonPath('data.lines.0.sku.value', 'TO000057')
            ->assertJsonPath('data.lines.0.description.value', 'TORNILLO CORTAMALEZA')
            ->assertJsonPath('data.lines.0.expected_quantity.value', '4.00')
            ->assertJsonCount(1, 'data.lines');

        $warnings = $response->json('data.lines.0.warnings');
        $this->assertContains('sku_posible_confusion_o_cero', array_column($warnings, 'code'));

        $contenido = (string) $response->getContent();

        foreach (['clave_trabajo', 'lease_token', 'fencing_token', 'referencia_privada', 'storage_key', 'pedidos/'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $contenido);
        }
    }

    public function test_attempt_get_devuelve_404_para_recurso_ajeno_o_inexistente(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();
        $intentoId = (int) $this->subir($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO)
            ->json('data.intento.id');

        $otro = $this->pedido();
        $this->get($this->urlIntentos($otro, $intentoId))->assertStatus(404);
        $this->get($this->urlIntentos($pedido, 999999))->assertStatus(404);
        $this->get($this->urlIntentos($pedido, $intentoId))->assertOk();
    }

    public function test_attempt_get_requiere_capacidad_de_lectura(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();
        $intentoId = (int) $this->subir($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO)
            ->json('data.intento.id');

        Sanctum::actingAs($this->usuario('mecanico'));
        $this->get($this->urlIntentos($pedido, $intentoId))->assertStatus(403);
    }

    public function test_reprocess_bloquea_con_intento_activo_y_crea_generacion_al_liberarse(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();

        $intentoId = (int) $this->subir($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO)
            ->json('data.intento.id');

        // Intento activo: 409 sin crear nada.
        $conflicto = $this->post($this->urlReproceso($pedido, $this->documentoId($intentoId)))
            ->assertStatus(409);

        $conflicto->assertJsonPath('data.intento.id', $intentoId);
        $this->assertDatabaseCount('pedido_ocr_intentos', 1);
        Queue::assertPushed(ProcesarPedidoOcr::class, 1);

        // Se libera (completa) y el reproceso crea una generación nueva.
        app(PedidoOcrService::class)->procesar(PedidoOcrIntento::query()->findOrFail($intentoId));

        $response = $this->post($this->urlReproceso($pedido, $this->documentoId($intentoId)))
            ->assertStatus(202);

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.estado', 'pendiente');

        $nuevoId = (int) $response->json('data.id');
        $this->assertNotSame($intentoId, $nuevoId);

        $this->assertSame('obsoleto', PedidoOcrIntento::query()->findOrFail($intentoId)->estado);
        $this->assertDatabaseCount('pedido_ocr_intentos', 2);
        Queue::assertPushed(ProcesarPedidoOcr::class, 2);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_reprocess_rechaza_factura_y_roles_sin_capacidad(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();

        $facturaId = (int) $this->subir($pedido, PedidoDocumentoService::ROL_FACTURA)
            ->assertStatus(201)
            ->json('data.id');
        $siigoId = (int) $this->subir($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO)
            ->assertStatus(202)
            ->json('data.documento.id');

        // Factura: 422 (el OCR es solo para pantallazos Siigo).
        $this->post($this->urlReproceso($pedido, $facturaId))
            ->assertStatus(422)
            ->assertJsonValidationErrors('document');

        // Rol sin pedidos.document.write: 403.
        Sanctum::actingAs($this->usuario('auxiliar_bodega'));
        $this->post($this->urlReproceso($pedido, $siigoId))->assertStatus(403);

        $this->assertDatabaseCount('pedido_ocr_intentos', 1);

        // Documento ajeno: 404.
        Sanctum::actingAs($this->usuario('contabilidad'));
        $otro = $this->pedido();
        $this->post($this->urlReproceso($otro, $siigoId))->assertStatus(404);
    }

    public function test_flag_off_oculta_los_endpoints_ocr(): void
    {
        config(['inventory_orders.enabled' => false]);

        $uuid = (string) Str::uuid();

        $this->get("/api/web/inventory-orders/{$uuid}/ocr-attempts/1")->assertStatus(404);
        $this->post("/api/web/inventory-orders/{$uuid}/documents/1/reprocess")->assertStatus(404);
    }

    public function test_flujo_ocr_completo_no_crea_transacciones_de_inventario(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();

        $intentoId = (int) $this->subir($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO)
            ->json('data.intento.id');

        $service = app(PedidoOcrService::class);
        $this->assertSame(
            'completado',
            $service->procesar(PedidoOcrIntento::query()->findOrFail($intentoId))->resultado,
        );

        $this->post($this->urlReproceso($pedido, $this->documentoId($intentoId)))->assertStatus(202);

        $reproceso = PedidoOcrIntento::query()->orderByDesc('intento_id')->firstOrFail();
        $this->assertSame('completado', $service->procesar($reproceso)->resultado);

        $this->assertSame(2, PedidoOcrIntento::query()->count());
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('bodega_producto', 0);
    }

    private function urlDocumentos(PedidoInventario $pedido): string
    {
        return "/api/web/inventory-orders/{$pedido->uuid}/documents";
    }

    private function urlIntentos(PedidoInventario $pedido, int $intentoId): string
    {
        return "/api/web/inventory-orders/{$pedido->uuid}/ocr-attempts/{$intentoId}";
    }

    private function urlReproceso(PedidoInventario $pedido, int $documentoId): string
    {
        return "/api/web/inventory-orders/{$pedido->uuid}/documents/{$documentoId}/reprocess";
    }

    private function subir(PedidoInventario $pedido, string $rol): TestResponse
    {
        return $this->post($this->urlDocumentos($pedido), [
            'rol' => $rol,
            'file' => $this->uploadJpeg(),
        ]);
    }

    private function documentoId(int $intentoId): int
    {
        return (int) PedidoOcrIntento::query()->findOrFail($intentoId)->documento_id;
    }
}
