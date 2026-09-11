<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\InvariantePedidoException;
use App\Domain\InventoryOrders\OcrIntentoActivoException;
use App\Domain\InventoryOrders\PedidoEstado;
use App\Models\PedidoEvento;
use App\Models\PedidoOcrIntento;
use App\Services\Inventory\Orders\Ocr\FakePedidoOcrProvider;
use App\Services\Inventory\Orders\Ocr\OcrException;
use App\Services\Inventory\Orders\Ocr\OcrProcesamientoResultado;
use App\Services\Inventory\Orders\PedidoDocumentoService;
use App\Services\Inventory\Orders\PedidoOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\InventoryOrders\Concerns\PedidoFixtures;
use Tests\Feature\InventoryOrders\Concerns\PedidoOcrFixtures;
use Tests\TestCase;

/**
 * Ciclo de vida del intento OCR (U4): solicitud idempotente, lease/fencing,
 * reintentos, cancelación y obsolescencia. Sin efectos de stock.
 */
class PedidoOcrServiceTest extends TestCase
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

    public function test_solicitar_crea_intento_y_evento_en_una_transaccion_y_es_idempotente(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);
        $service = app(PedidoOcrService::class);

        $primero = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);
        $segundo = $service->solicitar($documento->fresh(), PedidoOcrService::ORIGEN_UPLOAD, $actor);

        $this->assertSame($primero->intento_id, $segundo->intento_id);
        $this->assertSame('pendiente', $primero->estado);
        $this->assertSame(3, $primero->max_intentos);
        $this->assertSame(0, $primero->intentos);
        $this->assertDatabaseCount('pedido_ocr_intentos', 1);
        $this->assertDatabaseCount('pedido_eventos', 1);

        $evento = PedidoEvento::query()->where('event_type', 'ocr_solicitado')->firstOrFail();
        $this->assertSame('pendiente', $evento->estado_despacho);
        $this->assertSame(0, $evento->intentos_despacho);
        $this->assertSame($primero->intento_id, $evento->datos_despues['intento_id']);
        $this->assertSame('upload', $evento->datos_despues['origen']);
        $this->assertSame('contabilidad', $evento->actor_rol_snapshot);
        $this->assertSame($actor->id, $evento->actor_user_id);

        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_solicitar_rechaza_factura_y_pedido_cancelado(): void
    {
        $actor = $this->usuario('contabilidad');
        $service = app(PedidoOcrService::class);

        $pedido = $this->pedido();
        $factura = $this->documentoOcr($pedido, PedidoDocumentoService::ROL_FACTURA, 1);

        try {
            $service->solicitar($factura, PedidoOcrService::ORIGEN_UPLOAD, $actor);
            $this->fail('La factura no debe admitir OCR.');
        } catch (InvariantePedidoException) {
            $this->assertDatabaseCount('pedido_ocr_intentos', 0);
        }

        $cancelado = $this->pedido();
        $documento = $this->documentoOcr($cancelado);
        $cancelado->transicionarA(PedidoEstado::CANCELADO);

        try {
            $service->solicitar($documento->fresh(), PedidoOcrService::ORIGEN_UPLOAD, $actor);
            $this->fail('Un pedido cancelado no debe admitir OCR.');
        } catch (InvariantePedidoException) {
            $this->assertDatabaseCount('pedido_ocr_intentos', 0);
        }
    }

    public function test_procesar_exitoso_completa_con_contrato_o01(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);

        $this->usarProveedor(new FakePedidoOcrProvider);
        $service = app(PedidoOcrService::class);

        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);
        $resultado = $service->procesar($intento);

        $this->assertSame(OcrProcesamientoResultado::COMPLETADO, $resultado->resultado);

        $intento->refresh();

        $this->assertSame('completado', $intento->estado);
        $this->assertSame(1, $intento->intentos);
        $this->assertSame(1, $intento->fencing_token);
        $this->assertNull($intento->lease_token);
        $this->assertNotNull($intento->started_at);
        $this->assertNotNull($intento->finished_at);
        $this->assertSame('siigo-mapper-1.0.0', $intento->mapper_version);
        $this->assertSame('fake-1.0.0', $intento->proveedor_version);
        $this->assertNull($intento->error_code);

        $contrato = $intento->resultado;
        $this->assertSame(1, $contrato['schema_version']);
        $this->assertSame('OSCAR DARÍO DÍAZ CASARUBIAS', $contrato['supplier']['value']);
        $this->assertSame('TO000057', $contrato['lines'][0]['sku']['value']);
        $this->assertSame('TORNILLO CORTAMALEZA', $contrato['lines'][0]['description']['value']);
        $this->assertSame('4.00', $contrato['lines'][0]['expected_quantity']['value']);
        $this->assertFalse($contrato['requires_human_revision']);
        $this->assertCount(1, $contrato['lines']);

        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_procesar_sin_lineas_queda_requiere_revision_y_no_repite_automaticamente(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);

        $llamadas = 0;
        $this->usarProveedor($this->proveedorFake(function () use (&$llamadas): array {
            $llamadas++;

            return ['provider' => 'test', 'provider_version' => 'test-1', 'full_text' => '', 'pages' => []];
        }));

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        $resultado = $service->procesar($intento);

        $this->assertSame(OcrProcesamientoResultado::REQUIERE_REVISION, $resultado->resultado);
        $this->assertSame(1, $llamadas);

        $intento->refresh();
        $this->assertSame('requiere_revision', $intento->estado);
        $this->assertTrue($intento->resultado['requires_human_revision']);
        $this->assertSame([], $intento->resultado['lines']);
        $this->assertContains('sin_lineas_detectadas', array_column($intento->resultado['warnings'], 'code'));

        // O10: un resultado parcial no re-dispara OCR automáticamente.
        $segundo = $service->procesar($intento->fresh());
        $this->assertSame(OcrProcesamientoResultado::NO_OP, $segundo->resultado);
        $this->assertSame(1, $llamadas);
    }

    public function test_error_reintentable_libera_lease_y_agota_intentos_hasta_fallido(): void
    {
        config(['inventory_orders.ocr.max_intentos' => 2]);

        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);

        $llamadas = 0;
        $this->usarProveedor($this->proveedorFake(function () use (&$llamadas): array {
            $llamadas++;

            throw OcrException::reintentable('proveedor_timeout', 'timeout de prueba');
        }));

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        $primero = $service->procesar($intento);
        $this->assertSame(OcrProcesamientoResultado::REINTENTAR, $primero->resultado);

        $intento->refresh();
        $this->assertSame('procesando', $intento->estado);
        $this->assertSame(1, $intento->intentos);
        $this->assertSame('proveedor_timeout', $intento->error_code);
        $this->assertNull($intento->lease_token);
        $this->assertTrue($intento->lease_expires_at->isPast(), 'El lease liberado debe ser reclamable de inmediato.');

        $segundo = $service->procesar($intento->fresh());
        $this->assertSame(OcrProcesamientoResultado::FALLIDO, $segundo->resultado);

        $intento->refresh();
        $this->assertSame('fallido', $intento->estado);
        $this->assertSame(2, $intento->intentos);
        $this->assertNotNull($intento->finished_at);

        $tercero = $service->procesar($intento->fresh());
        $this->assertSame(OcrProcesamientoResultado::NO_OP, $tercero->resultado);
        $this->assertSame(2, $llamadas);

        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_error_terminal_4xx_falla_de_inmediato_sin_reintentos(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);

        $llamadas = 0;
        $this->usarProveedor($this->proveedorFake(function () use (&$llamadas): array {
            $llamadas++;

            throw OcrException::terminal('proveedor_error_terminal', 'credencial inválida');
        }));

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        $resultado = $service->procesar($intento);

        $this->assertSame(OcrProcesamientoResultado::FALLIDO, $resultado->resultado);
        $this->assertSame('proveedor_error_terminal', $resultado->errorCode);

        $intento->refresh();
        $this->assertSame('fallido', $intento->estado);
        $this->assertSame(1, $intento->intentos);
        $this->assertNotNull($intento->finished_at);
        $this->assertSame(1, $llamadas);
    }

    public function test_fencing_descarta_el_resultado_de_un_worker_reemplazado(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);
        $intentoId = (int) $intento->intento_id;

        $this->usarProveedor($this->proveedorFake(function () use ($intentoId): array {
            // Un worker más nuevo reclama el intento mientras este corre.
            PedidoOcrIntento::query()->whereKey($intentoId)->update([
                'lease_token' => 'lease-nuevo',
                'fencing_token' => 99,
            ]);

            return $this->crudoValido();
        }));

        $resultado = app(PedidoOcrService::class)->procesar($intento);

        $this->assertSame(OcrProcesamientoResultado::NO_OP, $resultado->resultado);

        $intento->refresh();
        $this->assertNull($intento->resultado, 'Un resultado fenced jamás sobrescribe el intento vigente.');
        $this->assertSame('lease-nuevo', $intento->lease_token);
        $this->assertSame(99, $intento->fencing_token);
        $this->assertSame('procesando', $intento->estado);

        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_pedido_cancelado_no_procesa_y_cancela_el_intento(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);

        $llamadas = 0;
        $this->usarProveedor($this->proveedorFake(function () use (&$llamadas): array {
            $llamadas++;

            return $this->crudoValido();
        }));

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        $pedido->transicionarA(PedidoEstado::CANCELADO);

        $resultado = $service->procesar($intento);

        $this->assertSame(OcrProcesamientoResultado::CANCELADO, $resultado->resultado);
        $this->assertSame(0, $llamadas, 'Un pedido cancelado no debe llamar al proveedor.');

        $intento->refresh();
        $this->assertSame('cancelado', $intento->estado);
        $this->assertNull($intento->resultado);
        $this->assertNotNull($intento->finished_at);
    }

    public function test_reproceso_crea_nueva_generacion_y_obsolesce_resultados_previos(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);

        $this->usarProveedor(new FakePedidoOcrProvider);
        $service = app(PedidoOcrService::class);

        $primero = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        // Mientras hay un intento activo, solicitar no crea duplicados.
        $duplicado = $service->solicitar($documento->fresh(), PedidoOcrService::ORIGEN_UPLOAD, $actor);
        $this->assertSame((int) $primero->intento_id, (int) $duplicado->intento_id, 'Solicitar no crea intentos duplicados.');

        $this->assertSame(OcrProcesamientoResultado::COMPLETADO, $service->procesar($primero)->resultado);

        // Completado: el reproceso explícito crea una generación nueva y marca
        // el resultado anterior obsoleto.
        $reproceso = $service->solicitar($documento->fresh(), PedidoOcrService::ORIGEN_REPROCESO, $actor);

        $this->assertNotSame((int) $primero->intento_id, (int) $reproceso->intento_id);
        $this->assertSame('pendiente', $reproceso->estado);
        $this->assertNotSame($primero->clave_trabajo, $reproceso->clave_trabajo);

        $primero->refresh();
        $this->assertSame('obsoleto', $primero->estado);
        $this->assertNotNull($primero->resultado, 'El resultado obsoleto se conserva para historial.');
        $this->assertDatabaseCount('pedido_ocr_intentos', 2);

        // O08/O09: un worker tardío del intento obsoleto no re-OCR ni pisa nada.
        $resultadoTardio = $service->procesar($primero->fresh());
        $this->assertSame(OcrProcesamientoResultado::NO_OP, $resultadoTardio->resultado);

        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_reproceso_con_intento_activo_lanza_excepcion_de_conflicto(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);
        $service = app(PedidoOcrService::class);

        $activo = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        try {
            $service->solicitar($documento->fresh(), PedidoOcrService::ORIGEN_REPROCESO, $actor, true);
            $this->fail('Un intento activo debe bloquear el reproceso.');
        } catch (OcrIntentoActivoException $exception) {
            $this->assertSame((int) $activo->intento_id, $exception->intentoId());
        }

        $this->assertDatabaseCount('pedido_ocr_intentos', 1);
    }

    public function test_google_stub_deja_fallido_con_error_claro_y_cero_red(): void
    {
        Http::preventStrayRequests();

        config(['inventory_orders.ocr.provider' => 'google']);

        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        $this->assertSame('google', $intento->proveedor);

        $resultado = $service->procesar($intento);

        $this->assertSame(OcrProcesamientoResultado::FALLIDO, $resultado->resultado);
        $this->assertSame('proveedor_no_habilitado', $resultado->errorCode);

        $intento->refresh();
        $this->assertSame('fallido', $intento->estado);
        $this->assertSame('proveedor_no_habilitado', $intento->error_code);
        $this->assertStringContainsString('proveedor_no_habilitado', (string) $intento->error_mensaje);
        $this->assertSame(1, $intento->intentos);
    }

    public function test_derivado_ausente_falla_sin_llamar_al_proveedor(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $documento = $this->documentoOcr($pedido);

        $llamadas = 0;
        $this->usarProveedor($this->proveedorFake(function () use (&$llamadas): array {
            $llamadas++;

            return $this->crudoValido();
        }));

        Storage::disk('local')->delete(
            PedidoDocumentoService::derivativePathFor($documento->storage_key),
        );

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        $resultado = $service->procesar($intento);

        $this->assertSame(OcrProcesamientoResultado::FALLIDO, $resultado->resultado);
        $this->assertSame('documento_derivado_ausente', $resultado->errorCode);
        $this->assertSame(0, $llamadas);

        $intento->refresh();
        $this->assertSame('fallido', $intento->estado);
        $this->assertSame('documento_derivado_ausente', $intento->error_code);
    }
}
