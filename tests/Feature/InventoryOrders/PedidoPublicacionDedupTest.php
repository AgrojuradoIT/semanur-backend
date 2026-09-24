<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\PedidoEstado;
use App\Models\PedidoAlerta;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Services\Inventory\Orders\PedidoDocumentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\InventoryOrders\Concerns\PedidoPublicacionFixtures;
use Tests\TestCase;

/**
 * Deduplicación documental U5 (plan secciones 9 y 10): clave única cuando hay
 * identidad fiable, sospecha auditada cuando no la hay, y re-publicación tras
 * retiro sin duplicar pedido.
 */
class PedidoPublicacionDedupTest extends TestCase
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

    public function test_colision_de_clave_documental_es_409_con_la_referencia_del_otro_pedido(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();

        $primero = $this->pedidoPublicable($actor, [
            'proveedor_clave' => 'PROV-A',
            'referencia_factura' => 'FV-1',
        ]);

        $this->publicarPedido($primero, $actor)->assertOk();

        $segundo = $this->pedidoPublicable($actor, [
            'proveedor_clave' => 'PROV-A',
            'referencia_factura' => 'FV-1',
        ]);

        $respuesta = $this->publicarPedido($segundo, $actor)->assertStatus(409);

        $this->assertStringContainsString('Documento duplicado', (string) $respuesta->json('message'));
        $this->assertSame($primero->uuid, $respuesta->json('candidatos.0.pedido_uuid'));
        $this->assertContains('clave_documental', $respuesta->json('candidatos.0.motivos'));
        $this->assertSame(PedidoEstado::BORRADOR, $segundo->fresh()->estado);
        $this->assertSame(1, PedidoEvento::query()->where('event_type', 'pedido_publicado')->count());
    }

    public function test_la_clave_normaliza_mayusculas_espacios_y_trim(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();

        $primero = $this->pedidoPublicable($actor, [
            'proveedor_clave' => 'proveedor x',
            'referencia_factura' => 'fv 001',
        ]);

        $this->publicarPedido($primero, $actor)->assertOk();

        $segundo = $this->pedidoPublicable($actor, [
            'proveedor_clave' => '  PROVEEDOR   X ',
            'referencia_factura' => 'FV   001',
        ]);

        $this->publicarPedido($segundo, $actor)->assertStatus(409);
        $this->assertSame('PROVEEDOR X|FV 001', $primero->fresh()->clave_documental_normalizada);
    }

    public function test_republicar_tras_retiro_conserva_la_clave_y_no_duplica_el_pedido(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();

        $pedido = $this->pedidoPublicable($actor, [
            'proveedor_clave' => 'PROV-R',
            'referencia_factura' => 'FV-R',
        ]);

        $this->publicarPedido($pedido, $actor)->assertOk();
        $clave = $pedido->fresh()->clave_documental_normalizada;

        Sanctum::actingAs($actor);
        $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/return-for-correction", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 2,
            'motivo' => 'Error documental en la factura',
        ])->assertOk();

        $this->publicarPedido($pedido->fresh(), $actor)->assertOk();

        $this->assertSame(1, PedidoInventario::query()->count());
        $this->assertSame($clave, $pedido->fresh()->clave_documental_normalizada);
        $this->assertSame(2, PedidoEvento::query()->where('event_type', 'pedido_publicado')->count());
        $this->assertSame(2, PedidoAlerta::query()->where('tipo', 'pedido_publicado')->count());
        $this->assertNull($pedido->fresh()->cancel_motivo);
    }

    public function test_sospecha_por_sha256_bloquea_sin_motivo(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();

        $sha = hash('sha256', 'documento-compartido');
        $primero = $this->pedidoSinIdentidad('A', 'PROVEEDOR A', 'SKU-A', $sha);
        $segundo = $this->pedidoSinIdentidad('B', 'PROVEEDOR B', 'SKU-B', $sha);

        $respuesta = $this->publicarPedido($segundo, $actor)->assertStatus(409);

        $respuesta->assertJsonPath('requiere_motivo_compra_distinta', true);
        $this->assertSame($primero->uuid, $respuesta->json('candidatos.0.pedido_uuid'));
        $this->assertContains('sha256_duplicado', $respuesta->json('candidatos.0.motivos'));
        $this->assertSame(PedidoEstado::BORRADOR, $segundo->fresh()->estado);
        $this->assertSame(0, PedidoEvento::query()->where('event_type', 'pedido_publicado')->count());
    }

    public function test_motivo_compra_distinta_permite_publicar_y_queda_auditado(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();

        $sha = hash('sha256', 'documento-compartido-2');
        $primero = $this->pedidoSinIdentidad('A', 'PROVEEDOR A', 'SKU-A', $sha);
        $segundo = $this->pedidoSinIdentidad('B', 'PROVEEDOR B', 'SKU-B', $sha);

        $motivo = 'Compra realmente distinta verificada con el proveedor';

        $this->publicarPedido($segundo, $actor, ['motivo_compra_distinta' => $motivo])
            ->assertOk()
            ->assertJsonPath('data.motivo_compra_distinta', $motivo);

        $evento = PedidoEvento::query()
            ->where('event_type', 'pedido_publicado')
            ->where('pedido_id', $segundo->pedido_id)
            ->firstOrFail();

        $this->assertSame($motivo, $evento->datos_despues['motivo_compra_distinta']);
        $this->assertContains($primero->uuid, $evento->datos_despues['sospechas_omitidas']);
    }

    public function test_heuristica_proveedor_fecha_linea_bloquea_y_admite_override(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();

        $primero = $this->pedidoSinIdentidad('H1', 'PROVEEDOR HEURISTICA', 'SKU-H', hash('sha256', 'doc-h1'));
        $segundo = $this->pedidoSinIdentidad('H2', '  proveedor   heuristica ', 'SKU-H', hash('sha256', 'doc-h2'));

        $respuesta = $this->publicarPedido($segundo, $actor)->assertStatus(409);

        $this->assertContains('proveedor_fecha_linea', $respuesta->json('candidatos.0.motivos'));
        $this->assertSame($primero->uuid, $respuesta->json('candidatos.0.pedido_uuid'));

        $this->publicarPedido($segundo->fresh(), $actor, [
            'motivo_compra_distinta' => 'Segunda compra del mismo proveedor en el día',
        ])->assertOk();
    }

    public function test_pedido_cancelado_no_cuenta_como_sospecha(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();

        $sha = hash('sha256', 'documento-cancelado');
        $cancelado = $this->pedidoSinIdentidad('C', 'PROVEEDOR CANCELADO', 'SKU-C', $sha);
        $cancelado->transicionarA(PedidoEstado::CANCELADO, [
            'cancelled_at' => now(),
            'cancel_motivo' => 'Anulado por prueba',
        ]);

        $nuevo = $this->pedidoSinIdentidad('N', 'PROVEEDOR CANCELADO', 'SKU-C', $sha);

        $this->publicarPedido($nuevo, $actor)->assertOk();
        $this->assertSame(PedidoEstado::PENDIENTE_RECEPCION, $nuevo->fresh()->estado);
    }

    public function test_clave_documental_demasiado_larga_es_422(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();

        $pedido = $this->pedidoPublicable($actor, [
            'proveedor_clave' => str_repeat('A', 120),
            'referencia_factura' => str_repeat('B', 120),
        ]);

        $this->publicarPedido($pedido, $actor)
            ->assertStatus(422)
            ->assertJsonValidationErrors('proveedor_clave');
    }

    private function pedidoSinIdentidad(
        string $etiqueta,
        string $proveedor,
        string $lineaSku,
        string $shaCompartido,
    ): PedidoInventario {
        $pedido = PedidoInventario::create([
            'bodega_id' => $this->bodega()->bodega_id,
            'created_by' => $this->usuario('contabilidad')->id,
            'proveedor_nombre_confirmado' => $proveedor,
            'proveedor_clave' => null,
            'referencia_factura' => null,
        ]);

        $this->lineaConfirmada($pedido, 1, $lineaSku, 'PRODUCTO '.$etiqueta, '4.00');
        $this->documentoCompleto($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO, [
            'sha256' => $shaCompartido,
        ]);
        $this->documentoCompleto($pedido, PedidoDocumentoService::ROL_FACTURA, [
            'sha256' => hash('sha256', $shaCompartido.'-factura'),
        ]);

        return $pedido->fresh(['lineas', 'documentos']);
    }
}
