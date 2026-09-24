<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\PedidoEstado;
use App\Jobs\ProcesarPedidoOcr;
use App\Models\PedidoDocumento;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\User;
use App\Services\Inventory\Orders\PedidoDocumentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Feature\InventoryOrders\Concerns\PedidoDocumentoFixtures;
use Tests\Feature\InventoryOrders\Concerns\PedidoFixtures;
use Tests\TestCase;

class PedidoDocumentoUploadTest extends TestCase
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

    // ─── Camino feliz y layout privado ────────────────────

    public function test_jpeg_and_png_uploads_create_private_original_and_derivative(): void
    {
        $contabilidad = $this->usuario('contabilidad');
        Sanctum::actingAs($contabilidad);
        $pedido = $this->pedido();

        // U4: el pantallazo Siigo dispara OCR y responde 202 con intento.
        $jpeg = $this->post($this->urlDocumentos($pedido), [
            'rol' => PedidoDocumentoService::ROL_PANTALLAZO_SIIGO,
            'file' => $this->uploadJpeg(),
        ])->assertStatus(202);

        $jpeg->assertJsonPath('success', true)
            ->assertJsonPath('data.documento.rol', 'pantallazo_siigo')
            ->assertJsonPath('data.documento.revision', 1)
            ->assertJsonPath('data.documento.mime', 'image/jpeg')
            ->assertJsonPath('data.documento.width', 64)
            ->assertJsonPath('data.documento.height', 48)
            ->assertJsonPath('data.documento.created_by', $contabilidad->id)
            ->assertJsonPath('data.intento.estado', 'pendiente')
            ->assertJsonPath('data.intento.proveedor', 'fake');

        $this->assertStringNotContainsString('storage_key', $jpeg->getContent());
        $this->assertStringNotContainsString('pedidos/', $jpeg->getContent());
        $jpeg->assertJsonPath(
            'data.documento.content_url',
            "/api/web/inventory-orders/{$pedido->uuid}/documents/".$jpeg->json('data.documento.id').'/content',
        );

        $png = $this->post($this->urlDocumentos($pedido), [
            'rol' => PedidoDocumentoService::ROL_FACTURA,
            'file' => $this->uploadPng(),
        ])->assertStatus(201);

        $png->assertJsonPath('data.mime', 'image/png')
            ->assertJsonPath('data.revision', 1);

        $this->assertDatabaseCount('pedido_documentos', 2);
        $this->assertDatabaseCount('pedido_eventos', 3);
        $this->assertDatabaseCount('pedido_ocr_intentos', 1);

        Queue::assertPushed(ProcesarPedidoOcr::class, 1);
        Queue::assertPushed(
            ProcesarPedidoOcr::class,
            fn (ProcesarPedidoOcr $job): bool => $job->intentoId === (int) $jpeg->json('data.intento.id'),
        );

        $eventoOcr = PedidoEvento::query()->where('event_type', 'ocr_solicitado')->firstOrFail();
        $this->assertSame('despachado', $eventoOcr->estado_despacho);
        $this->assertNotNull($eventoOcr->despachado_at);
        $this->assertSame($jpeg->json('data.intento.id'), $eventoOcr->datos_despues['intento_id']);

        foreach (PedidoDocumento::query()->get() as $documento) {
            $this->assertStringStartsWith("pedidos/{$pedido->uuid}/", (string) $documento->storage_key);
            $this->assertTrue(Storage::disk('local')->exists($documento->storage_key));
            $this->assertTrue(
                Storage::disk('local')->exists(PedidoDocumentoService::derivativePathFor($documento->storage_key)),
                'El derivado preview.jpg debe existir junto al original.',
            );
            $this->assertSame(64, strlen((string) $documento->sha256));
        }

        $this->assertSame([], Storage::disk('public')->allFiles('pedidos'));

        $evento = PedidoEvento::query()->where('event_type', 'documento_cargado')->firstOrFail();
        $this->assertSame('documento_cargado', $evento->event_type);
        $this->assertSame('contabilidad', $evento->actor_rol_snapshot);
        $this->assertNull($evento->datos_antes);
    }

    public function test_original_is_preserved_and_derivative_is_reencoded_jpeg(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();
        $bytes = $this->pngBytes();

        $this->post($this->urlDocumentos($pedido), [
            'rol' => PedidoDocumentoService::ROL_FACTURA,
            'file' => UploadedFile::fake()->createWithContent('foto.png', $bytes),
        ])->assertStatus(201);

        $documento = PedidoDocumento::query()->firstOrFail();
        $this->assertSame($bytes, Storage::disk('local')->get($documento->storage_key));
        $this->assertSame(hash('sha256', $bytes), $documento->sha256);

        $derivado = Storage::disk('local')->get(PedidoDocumentoService::derivativePathFor($documento->storage_key));
        $info = getimagesizefromstring($derivado);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertSame(64, $info[0]);
        $this->assertSame(48, $info[1]);
    }

    public function test_exif_orientation_is_applied_to_derivative_without_upscaling(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();
        $bytes = $this->jpegConOrientacion(6, 64, 48);

        $this->post($this->urlDocumentos($pedido), [
            'rol' => PedidoDocumentoService::ROL_FACTURA,
            'file' => UploadedFile::fake()->createWithContent('rotada.jpg', $bytes),
        ])->assertStatus(201);

        $documento = PedidoDocumento::query()->firstOrFail();

        // La evidencia conserva sus dimensiones reales registradas.
        $this->assertSame(64, $documento->width);
        $this->assertSame(48, $documento->height);

        // El derivado rota 90° (orientation 6) => 48x64, sin escalar.
        $derivado = Storage::disk('local')->get(PedidoDocumentoService::derivativePathFor($documento->storage_key));
        $info = getimagesizefromstring($derivado);
        $this->assertSame(48, $info[0]);
        $this->assertSame(64, $info[1]);
    }

    // ─── Revisiones y referencias ─────────────────────────

    public function test_revision_increments_per_order_and_role(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();

        $this->postDocumento($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO)
            ->assertStatus(202)->assertJsonPath('data.documento.revision', 1);
        $this->postDocumento($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO)
            ->assertStatus(202)->assertJsonPath('data.documento.revision', 2);
        $this->postDocumento($pedido, PedidoDocumentoService::ROL_FACTURA)
            ->assertStatus(201)->assertJsonPath('data.revision', 1);

        $this->assertDatabaseCount('pedido_ocr_intentos', 2);
    }

    public function test_replacement_and_line_references_must_belong_to_the_same_order(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();
        $otro = $this->pedido();

        $primero = $this->postDocumento($pedido, PedidoDocumentoService::ROL_FACTURA)
            ->assertStatus(201)
            ->json('data');

        $this->postDocumento($pedido, PedidoDocumentoService::ROL_FACTURA, [
            'replaces_documento_id' => $primero['id'],
        ])->assertStatus(201)->assertJsonPath('data.replaced_document_id', $primero['id']);

        $this->postDocumento($otro, PedidoDocumentoService::ROL_FACTURA, [
            'replaces_documento_id' => $primero['id'],
        ])->assertStatus(422)->assertJsonValidationErrors('replaces_documento_id');

        $this->postDocumento($pedido, PedidoDocumentoService::ROL_FACTURA, [
            'replaces_documento_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors('replaces_documento_id');

        $linea = $this->linea($pedido);
        $this->postDocumento($pedido, PedidoDocumentoService::ROL_FACTURA, [
            'pedido_linea_id' => $linea->linea_id,
        ])->assertStatus(201)->assertJsonPath('data.pedido_linea_id', $linea->linea_id);

        $lineaAjena = $this->linea($otro, 2);
        $this->postDocumento($pedido, PedidoDocumentoService::ROL_FACTURA, [
            'pedido_linea_id' => $lineaAjena->linea_id,
        ])->assertStatus(422)->assertJsonValidationErrors('pedido_linea_id');
    }

    public function test_o11_a_single_document_type_is_allowed_in_draft_without_publish_gate(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();

        $this->postDocumento($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO)->assertStatus(202);

        $this->assertDatabaseCount('pedido_documentos', 1);
        $this->assertDatabaseMissing('pedido_documentos', ['rol' => PedidoDocumentoService::ROL_FACTURA]);
    }

    // ─── O12: rechazos sin rastro ─────────────────────────

    public function test_o12_disguised_scripts_and_mismatched_content_are_rejected_without_traces(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();
        $jpeg = $this->jpegBytes();

        $casos = [
            'php disfrazado' => UploadedFile::fake()->createWithContent('foto.jpg', "<?php echo 'x';"),
            'svg disfrazado' => UploadedFile::fake()->createWithContent('foto.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            'xml disfrazado' => UploadedFile::fake()->createWithContent('foto.png', '<?xml version="1.0"?><root/>'),
            'jpeg con extension png' => UploadedFile::fake()->createWithContent('foto.png', $jpeg),
            'png con extension jpg' => UploadedFile::fake()->createWithContent('foto.jpg', $this->pngBytes()),
            'truncado' => UploadedFile::fake()->createWithContent('foto.jpg', substr($jpeg, 0, (int) (strlen($jpeg) * 0.5))),
            'extension no permitida' => UploadedFile::fake()->createWithContent('foto.exe', $jpeg),
            'texto plano' => UploadedFile::fake()->createWithContent('foto.jpg', 'esto no es una imagen'),
        ];

        foreach ($casos as $nombre => $file) {
            $this->post($this->urlDocumentos($pedido), [
                'rol' => PedidoDocumentoService::ROL_FACTURA,
                'file' => $file,
            ])->assertStatus(422, "Caso no rechazado: {$nombre}")
                ->assertJsonValidationErrors('file');
        }

        $this->assertDatabaseCount('pedido_documentos', 0);
        $this->assertDatabaseCount('pedido_eventos', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('pedidos'));
        $this->assertSame([], Storage::disk('public')->allFiles('pedidos'));
    }

    public function test_o12_file_size_limit_is_rejected_before_storing(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();

        config(['inventory_orders.documents.max_file_mb' => 0.0002]);

        $this->postDocumento($pedido, PedidoDocumentoService::ROL_FACTURA)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame([], Storage::disk('local')->allFiles('pedidos'));
    }

    public function test_o12_per_order_count_limit_is_enforced(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();

        config(['inventory_orders.documents.max_per_order' => 1]);

        $this->postDocumento($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO)->assertStatus(202);
        $this->postDocumento($pedido, PedidoDocumentoService::ROL_FACTURA)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('pedido_documentos', 1);
        $this->assertCount(2, Storage::disk('local')->allFiles('pedidos'));
    }

    public function test_o12_per_order_total_size_limit_is_enforced(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();

        $unArchivo = strlen($this->jpegBytes());
        config(['inventory_orders.documents.max_total_mb' => ($unArchivo + 100) / (1024 * 1024)]);

        $this->postDocumento($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO)->assertStatus(202);
        $this->postDocumento($pedido, PedidoDocumentoService::ROL_FACTURA)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('pedido_documentos', 1);
        $this->assertCount(2, Storage::disk('local')->allFiles('pedidos'));
    }

    public function test_o12_megapixel_limit_is_enforced(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();

        config(['inventory_orders.documents.max_megapixels' => 0.001]);

        $this->postDocumento($pedido, PedidoDocumentoService::ROL_FACTURA)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame([], Storage::disk('local')->allFiles('pedidos'));
    }

    public function test_upload_requires_document_write_capability(): void
    {
        $pedido = $this->pedido();

        Sanctum::actingAs($this->usuario('auxiliar_bodega'));
        $this->postDocumento($pedido, PedidoDocumentoService::ROL_FACTURA)->assertStatus(403);

        Sanctum::actingAs($this->usuario('contabilidad'));
        $this->postDocumento($pedido, PedidoDocumentoService::ROL_FACTURA)->assertStatus(201);
    }

    public function test_published_order_rejects_new_documents(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();
        $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION);

        $this->postDocumento($pedido->fresh(), PedidoDocumentoService::ROL_FACTURA)->assertStatus(403);
        $this->assertDatabaseCount('pedido_documentos', 0);
    }

    // ─── Compensación ─────────────────────────────────────

    public function test_compensation_removes_files_when_persistence_fails(): void
    {
        $actor = $this->usuario('contabilidad');
        Sanctum::actingAs($actor);
        $pedido = $this->pedido();

        $service = new class extends PedidoDocumentoService
        {
            protected function persistir(PedidoInventario $pedido, User $actor, array $datos): PedidoDocumento
            {
                throw new RuntimeException('fallo simulado de persistencia');
            }
        };

        try {
            $service->store($pedido, $this->uploadJpeg(), PedidoDocumentoService::ROL_FACTURA, null, null, $actor);

            $this->fail('Se esperaba RuntimeException de persistencia.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fallo simulado de persistencia', $exception->getMessage());
        }

        $this->assertDatabaseCount('pedido_documentos', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('pedidos'));
    }

    // ─── FIX-1 (#2433): preservación de archivos ajenos ───

    public function test_preexisting_foreign_files_under_pedidos_are_preserved(): void
    {
        // Archivo ajeno preexistente bajo el prefijo pedidos/.
        Storage::disk('local')->put('pedidos/_externo/preservar.txt', 'no-borrar');

        Sanctum::actingAs($this->usuario('contabilidad'));
        $pedido = $this->pedido();
        $this->postDocumento($pedido, PedidoDocumentoService::ROL_FACTURA)->assertStatus(201);

        $this->assertTrue(
            Storage::disk('local')->exists('pedidos/_externo/preservar.txt'),
            'Un archivo preexistente ajeno bajo pedidos/ no debe borrarse.',
        );
        $this->assertSame('no-borrar', Storage::disk('local')->get('pedidos/_externo/preservar.txt'));
    }

    private function urlDocumentos(PedidoInventario $pedido): string
    {
        return "/api/web/inventory-orders/{$pedido->uuid}/documents";
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postDocumento(PedidoInventario $pedido, string $rol, array $overrides = []): TestResponse
    {
        return $this->post($this->urlDocumentos($pedido), array_merge([
            'rol' => $rol,
            'file' => $this->uploadJpeg(),
        ], $overrides));
    }
}
