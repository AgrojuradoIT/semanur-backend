<?php

namespace Tests\Feature\InventoryOrders;

use App\Models\PedidoInventario;
use App\Services\Inventory\Orders\PedidoDocumentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\InventoryOrders\Concerns\PedidoDocumentoFixtures;
use Tests\Feature\InventoryOrders\Concerns\PedidoFixtures;
use Tests\TestCase;

class PedidoDocumentoContentTest extends TestCase
{
    use PedidoDocumentoFixtures, PedidoFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inventory_orders.enabled' => true]);
        $this->withHeaders(['Accept' => 'application/json']);
        Queue::fake();
        // FIX-1 (#2433): disco aislado por test. Sin esto, el
        // deleteDirectory('pedidos') borraba el disco local real.
        Storage::fake('local');
    }

    public function test_content_sets_private_security_headers_and_never_leaks_storage_keys(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();
        $documento = $this->subir($pedido, PedidoDocumentoService::ROL_FACTURA);

        $response = $this->get($this->urlContenido($pedido->uuid, $documento['id']))->assertOk();

        $response->assertHeader('Content-Type', 'image/jpeg');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('inline', $disposition);
        $this->assertStringNotContainsString('pedidos/', $disposition);

        $cuerpo = $response->streamedContent();
        $this->assertStringStartsWith("\xFF\xD8", $cuerpo, 'El derivado debe servirse como JPEG.');
    }

    public function test_o13_foreign_order_or_document_returns_404(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();
        $documento = $this->subir($pedido, PedidoDocumentoService::ROL_FACTURA);
        $otro = $this->pedido();

        // Documento real solicitado desde otro pedido.
        $this->get($this->urlContenido($otro->uuid, $documento['id']))->assertStatus(404);

        // Documento inexistente en el pedido correcto.
        $this->get($this->urlContenido($pedido->uuid, 999999))->assertStatus(404);

        // Pedido inexistente.
        $this->get($this->urlContenido((string) Str::uuid(), $documento['id']))->assertStatus(404);
    }

    public function test_o13_role_without_read_capability_gets_403(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();
        $documento = $this->subir($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO);

        Sanctum::actingAs($this->usuario('mecanico'));
        $this->get($this->urlContenido($pedido->uuid, $documento['id']))->assertStatus(403);
    }

    public function test_unauthenticated_content_request_gets_401(): void
    {
        $pedido = $this->pedido();

        $this->get($this->urlContenido($pedido->uuid, 1))->assertStatus(401);
    }

    public function test_flag_off_hides_document_endpoints_with_404(): void
    {
        config(['inventory_orders.enabled' => false]);
        $uuid = (string) Str::uuid();

        $this->post("/api/web/inventory-orders/{$uuid}/documents", [])->assertStatus(404);
        $this->get("/api/web/inventory-orders/{$uuid}/documents/1/content")->assertStatus(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function subir(PedidoInventario $pedido, string $rol): array
    {
        $response = $this->post("/api/web/inventory-orders/{$pedido->uuid}/documents", [
            'rol' => $rol,
            'file' => $this->uploadJpeg(),
        ]);

        if ($rol === PedidoDocumentoService::ROL_PANTALLAZO_SIIGO) {
            return $response->assertStatus(202)->json('data.documento');
        }

        return $response->assertStatus(201)->json('data');
    }

    private function urlContenido(string $orderUuid, int $documentoId): string
    {
        return "/api/web/inventory-orders/{$orderUuid}/documents/{$documentoId}/content";
    }
}
