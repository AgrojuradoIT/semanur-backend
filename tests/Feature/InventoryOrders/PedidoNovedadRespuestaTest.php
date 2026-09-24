<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\NovedadEstado;
use App\Models\PedidoAlerta;
use App\Models\PedidoEvento;
use App\Models\PedidoNovedad;
use App\Models\TransaccionInventario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\InventoryOrders\Concerns\PedidoDesfaseFixtures;
use Tests\TestCase;

/**
 * Respuestas documentales sobre novedades (U7; plan secciones 5, 11 y 12).
 *
 * Una respuesta NUNCA toca stock ni cantidades; contable/entrega avisan a
 * bodega (requiere nuevo conteo, sin ingreso automático) y la nota es solo
 * evento.
 */
class PedidoNovedadRespuestaTest extends TestCase
{
    use PedidoDesfaseFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inventory_orders.enabled' => true]);
        $this->configurarD1();

        $this->withHeaders(['Accept' => 'application/json']);
        $this->limpiarDocumentosOcr();
    }

    protected function tearDown(): void
    {
        $this->limpiarDocumentosOcr();

        parent::tearDown();
    }

    public function test_respuesta_contable_crea_evento_y_avisa_a_bodega_sin_stock(): void
    {
        $creador = $this->usuario('contabilidad');
        [$pedido, $receptor, $linea, $novedad] = $this->pedidoConNovedadAbierta(creador: $creador);

        $respuesta = $this->responder($creador, $pedido, $novedad->novedad_id)->assertOk();

        $respuesta->assertJsonPath('data.tipo', 'respuesta_contable')
            ->assertJsonPath('data.alertas_creadas', 1)
            ->assertJsonPath('data.novedad_id', (int) $novedad->novedad_id);

        $evento = PedidoEvento::query()->where('event_type', 'novedad_respondida')->sole();
        $this->assertSame((int) $creador->id, (int) $evento->actor_user_id);
        $this->assertSame('respuesta_contable', $evento->datos_despues['tipo_respuesta']);
        $this->assertTrue($evento->datos_despues['genera_alerta']);

        // La alerta llega a bodega habilitada y no a contabilidad.
        $alertas = PedidoAlerta::query()->where('tipo', 'respuesta_contable')->get();
        $this->assertCount(1, $alertas);
        $this->assertSame((int) $receptor->id, (int) $alertas->first()->destinatario_user_id);
        $this->assertFalse($alertas->contains('destinatario_user_id', (int) $creador->id));

        // Cero efectos de stock/cantidades.
        $this->assertSame(1, TransaccionInventario::query()->count());
        $this->assertSame('novedad', $linea->fresh()->estado);
        $this->assertSame(NovedadEstado::ABIERTA, $novedad->fresh()->estado);
        $this->assertSame(
            '2.00',
            \App\Services\Inventory\Orders\PedidoSnapshot::cantidadCanonica($novedad->fresh()->aptos_snapshot),
        );
    }

    public function test_entrega_complementaria_informada_avisa_a_bodega(): void
    {
        [$pedido, $receptor, , $novedad] = $this->pedidoConNovedadAbierta();

        $creador = $this->usuario('contabilidad');

        $this->responder($creador, $pedido, $novedad->novedad_id, [
            'tipo' => 'entrega_complementaria_informada',
            'mensaje' => 'El proveedor repondrá la unidad faltante el viernes.',
        ])->assertOk();

        $alerta = PedidoAlerta::query()->where('tipo', 'entrega_complementaria_informada')->sole();
        $this->assertSame((int) $receptor->id, (int) $alerta->destinatario_user_id);
        $this->assertSame(1, TransaccionInventario::query()->count());
    }

    public function test_nota_bodega_solo_deja_evento_sin_alerta(): void
    {
        [$pedido, $receptor, , $novedad] = $this->pedidoConNovedadAbierta();

        $this->responder($receptor, $pedido, $novedad->novedad_id, [
            'tipo' => 'nota_bodega',
            'mensaje' => 'La mercancía quedó separada e identificada como pendiente.',
        ])->assertOk();

        $this->assertSame(1, PedidoEvento::query()->where('event_type', 'novedad_respondida')->count());
        $this->assertSame(0, PedidoAlerta::query()->where('tipo', 'nota_bodega')->count());
        $this->assertSame(1, TransaccionInventario::query()->count());
    }

    public function test_respuesta_a_novedad_ajena_es_404_y_a_novedad_cerrada_409(): void
    {
        [$pedido, $actor, , $novedad] = $this->pedidoConNovedadAbierta();
        [, , , $novedadAjena] = $this->pedidoConNovedadAbierta();

        $this->responder($actor, $pedido, $novedadAjena->novedad_id)->assertStatus(404);

        $novedad->transicionarA(NovedadEstado::CERRADA, ['cerrada_at' => now()]);

        $this->responder($actor, $pedido, $novedad->novedad_id)->assertStatus(409);
        $this->assertSame(0, PedidoEvento::query()->where('event_type', 'novedad_respondida')->count());
    }

    public function test_documento_adjunto_debe_pertenecer_al_pedido(): void
    {
        [$pedido, $receptor, , $novedad] = $this->pedidoConNovedadAbierta();
        $otroPedido = $this->pedido();

        $documentoPropio = $this->documentoCompleto($pedido);
        $documentoAjeno = $this->documentoCompleto($otroPedido);

        // Documento de otro pedido: 404 y sin adjuntar.
        $this->responder($receptor, $pedido, $novedad->novedad_id, [
            'documento_id' => $documentoAjeno->documento_id,
        ])->assertStatus(404);

        $this->assertNull($novedad->fresh()->documento_id);

        // Documento propio: se adjunta como evidencia de la novedad.
        $this->responder($receptor, $pedido, $novedad->novedad_id, [
            'documento_id' => $documentoPropio->documento_id,
            'tipo' => 'nota_bodega',
            'mensaje' => 'Adjunto la foto del empaque recibido.',
        ])->assertOk();

        $this->assertSame($documentoPropio->documento_id, (int) $novedad->fresh()->documento_id);
    }

    public function test_respuesta_rechaza_cantidades_importes_y_actor(): void
    {
        [$pedido, $receptor, , $novedad] = $this->pedidoConNovedadAbierta();

        foreach ([
            'cantidad' => '3.00',
            'conteo_total' => '3.00',
            'cantidad_autorizada' => '3.00',
            'importe' => '75000.00',
            'moneda' => 'COP',
            'actor_user_id' => 999,
            'authorized_by' => 999,
        ] as $campo => $valor) {
            $this->responder($receptor, $pedido, $novedad->novedad_id, [$campo => $valor])
                ->assertStatus(422)
                ->assertJsonValidationErrors($campo);
        }

        $this->assertSame(0, PedidoEvento::query()->where('event_type', 'novedad_respondida')->count());
    }

    public function test_replay_de_respuesta_no_duplica_evento_ni_alerta(): void
    {
        [$pedido, $receptor, , $novedad] = $this->pedidoConNovedadAbierta();

        $creator = $this->usuario('contabilidad');
        $operacion = (string) Str::uuid();

        $this->responder($creator, $pedido, $novedad->novedad_id, [
            'client_operation_id' => $operacion,
        ])->assertOk()->assertJsonPath('idempotent_replay', false);

        $this->responder($creator, $pedido, $novedad->novedad_id, [
            'client_operation_id' => $operacion,
        ])->assertOk()->assertJsonPath('idempotent_replay', true);

        $this->assertSame(1, PedidoEvento::query()->where('event_type', 'novedad_respondida')->count());
        $this->assertSame(1, PedidoAlerta::query()->where('tipo', 'respuesta_contable')->count());

        // Mismo UUID con payload distinto = 409.
        $this->responder($creator, $pedido, $novedad->novedad_id, [
            'client_operation_id' => $operacion,
            'mensaje' => 'Otro mensaje distinto que no coincide con el original',
        ])->assertStatus(409);
    }
}
