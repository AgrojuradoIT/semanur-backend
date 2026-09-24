<?php

namespace Tests\Feature\InventoryOrders;

use App\Jobs\ProcesarPedidoOcr;
use App\Models\PedidoDocumento;
use App\Models\PedidoInventario;
use App\Services\Inventory\Orders\PedidoDocumentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\InventoryOrders\Concerns\PedidoDocumentoFixtures;
use Tests\Feature\InventoryOrders\Concerns\PedidoFixtures;
use Tests\TestCase;

/**
 * BE-1: la factura acepta PDF real (foto o PDF); el pantallazo sigue
 * image-only y la factura nunca alimenta OCR.
 *
 * Usa Storage::fake('local') para no tocar el disco real.
 */
class PedidoDocumentoFacturaPdfTest extends TestCase
{
    use PedidoDocumentoFixtures, PedidoFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inventory_orders.enabled' => true]);
        $this->withHeaders(['Accept' => 'application/json']);
        Queue::fake();
        Storage::fake('local');
    }

    public function test_factura_pdf_uploads_201_without_derivative_and_without_ocr(): void
    {
        $contabilidad = $this->usuario('contabilidad');
        Sanctum::actingAs($contabilidad);
        $pedido = $this->pedido();
        $bytes = $this->pdfBytes();

        $response = $this->post($this->urlDocumentos($pedido), [
            'rol' => PedidoDocumentoService::ROL_FACTURA,
            'file' => UploadedFile::fake()->createWithContent('factura.pdf', $bytes),
        ])->assertStatus(201);

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.rol', PedidoDocumentoService::ROL_FACTURA)
            ->assertJsonPath('data.mime', 'application/pdf')
            ->assertJsonPath('data.preview_disponible', false);

        $this->assertNull($response->json('data.width'));
        $this->assertNull($response->json('data.height'));

        $documento = PedidoDocumento::query()->firstOrFail();
        $this->assertSame('application/pdf', $documento->mime_type);
        $this->assertNull($documento->width);
        $this->assertNull($documento->height);
        $this->assertStringEndsWith('original.pdf', (string) $documento->storage_key);
        $this->assertSame($bytes, Storage::disk('local')->get($documento->storage_key));

        // Sin renderer en el proyecto: el original se conserva, sin derivado.
        $this->assertFalse(
            Storage::disk('local')->exists(PedidoDocumentoService::derivativePathFor($documento->storage_key)),
            'La factura PDF no debe generar preview.jpg.',
        );

        // La factura NUNCA dispara OCR: sin intento, sin evento outbox, sin job.
        $this->assertDatabaseCount('pedido_ocr_intentos', 0);
        $this->assertDatabaseMissing('pedido_eventos', ['event_type' => 'ocr_solicitado']);
        Queue::assertNotPushed(ProcesarPedidoOcr::class);

        // La descarga sirve el original como PDF, no como image/jpeg.
        $contenido = $this->get($this->urlContenido($pedido->uuid, $documento->documento_id))->assertOk();
        $contenido->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('.pdf', (string) $contenido->headers->get('Content-Disposition'));
        $this->assertSame($bytes, $contenido->streamedContent());
    }

    public function test_pantallazo_rejects_pdf_with_422(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();
        $pdf = $this->pdfBytes();

        // PDF con extensión .pdf.
        $this->post($this->urlDocumentos($pedido), [
            'rol' => PedidoDocumentoService::ROL_PANTALLAZO_SIIGO,
            'file' => UploadedFile::fake()->createWithContent('pantallazo.pdf', $pdf),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        // PDF disfrazado con extensión de imagen.
        $this->post($this->urlDocumentos($pedido), [
            'rol' => PedidoDocumentoService::ROL_PANTALLAZO_SIIGO,
            'file' => UploadedFile::fake()->createWithContent('pantallazo.jpg', $pdf),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('pedido_documentos', 0);
        $this->assertDatabaseCount('pedido_eventos', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_factura_rejects_disguised_and_truncated_pdf(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();

        // PDF real con extensión de imagen: la extensión y el MIME no coinciden.
        $this->post($this->urlDocumentos($pedido), [
            'rol' => PedidoDocumentoService::ROL_FACTURA,
            'file' => UploadedFile::fake()->createWithContent('factura.png', $this->pdfBytes()),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        // PDF sin cierre %%EOF: truncado.
        $this->post($this->urlDocumentos($pedido), [
            'rol' => PedidoDocumentoService::ROL_FACTURA,
            'file' => UploadedFile::fake()->createWithContent('factura.pdf', "%PDF-1.4\ncontenido incompleto"),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        // Texto plano con extensión .pdf.
        $this->post($this->urlDocumentos($pedido), [
            'rol' => PedidoDocumentoService::ROL_FACTURA,
            'file' => UploadedFile::fake()->createWithContent('factura.pdf', 'esto no es un pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('pedido_documentos', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_factura_image_still_uploads_201_with_preview(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();

        $response = $this->post($this->urlDocumentos($pedido), [
            'rol' => PedidoDocumentoService::ROL_FACTURA,
            'file' => $this->uploadPng(),
        ])->assertStatus(201);

        $response->assertJsonPath('data.mime', 'image/png')
            ->assertJsonPath('data.preview_disponible', true);

        $documento = PedidoDocumento::query()->firstOrFail();
        $this->assertTrue(
            Storage::disk('local')->exists(PedidoDocumentoService::derivativePathFor($documento->storage_key)),
        );
        $this->assertDatabaseCount('pedido_ocr_intentos', 0);
        Queue::assertNotPushed(ProcesarPedidoOcr::class);
    }

    public function test_reprocess_rejects_factura_pdf_with_422(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();

        $documentoId = $this->post($this->urlDocumentos($pedido), [
            'rol' => PedidoDocumentoService::ROL_FACTURA,
            'file' => UploadedFile::fake()->createWithContent('factura.pdf', $this->pdfBytes()),
        ])->assertStatus(201)->json('data.id');

        // El reproceso es pantallazo-only: la factura responde 422.
        $this->post("/api/web/inventory-orders/{$pedido->uuid}/documents/{$documentoId}/reprocess")
            ->assertStatus(422);

        $this->assertDatabaseCount('pedido_ocr_intentos', 0);
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n"
            ."1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            ."2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            ."3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >> endobj\n"
            ."trailer << /Root 1 0 R >>\n"
            ."%%EOF\n";
    }

    private function urlDocumentos(PedidoInventario $pedido): string
    {
        return "/api/web/inventory-orders/{$pedido->uuid}/documents";
    }

    private function urlContenido(string $pedidoUuid, int $documentoId): string
    {
        return "/api/web/inventory-orders/{$pedidoUuid}/documents/{$documentoId}/content";
    }
}
