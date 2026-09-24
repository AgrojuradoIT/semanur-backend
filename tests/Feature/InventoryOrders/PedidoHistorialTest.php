<?php

namespace Tests\Feature\InventoryOrders;

use App\Models\PedidoEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\InventoryOrders\Concerns\PedidoDesfaseFixtures;
use Tests\TestCase;

/**
 * Historial paginado del pedido (U7; plan secciones 11 y 14).
 *
 * Actor y cambios relevantes, orden descendente, sin secretos ni payloads
 * crudos y con 404 para un UUID ajeno.
 */
class PedidoHistorialTest extends TestCase
{
    use PedidoDesfaseFixtures, RefreshDatabase;

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

    public function test_historial_pagina_con_actor_y_orden_descendente(): void
    {
        $creador = $this->usuario('contabilidad');
        [$pedido, $receptor, , $novedad] = $this->pedidoConNovedadAbierta(creador: $creador);

        $respuesta = $this->historial($creador, $pedido, ['per_page' => 2])->assertOk();

        $respuesta->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2);

        $data = $respuesta->json('data');
        $this->assertCount(2, $data);
        $this->assertGreaterThan((int) $data[1]['evento_id'], (int) $data[0]['evento_id']);
        $this->assertSame('recibido_con_novedad', $data[0]['event_type']);
        $this->assertSame((int) $receptor->id, (int) $data[0]['actor']['id']);
        $this->assertSame((string) $receptor->name, $data[0]['actor']['nombre']);
        $this->assertSame('auxiliar_bodega', $data[0]['actor_rol_snapshot']);

        $total = (int) $respuesta->json('meta.total');
        $this->assertGreaterThanOrEqual(3, $total);
        $this->assertSame($total, PedidoEvento::query()->where('pedido_id', $pedido->pedido_id)->count());
        $this->assertNotNull($novedad);
    }

    public function test_historial_filtra_por_tipo_y_linea(): void
    {
        [$pedido, $receptor, $linea, $novedad] = $this->pedidoConNovedadAbierta();

        $this->autorizar($receptor, $pedido, $novedad->novedad_id)->assertOk();

        $porTipo = $this->historial($receptor, $pedido, ['tipo' => 'desfase_autorizado'])->assertOk();

        $this->assertCount(1, $porTipo->json('data'));
        $this->assertSame('desfase_autorizado', $porTipo->json('data.0.event_type'));
        $this->assertSame($linea->linea_id, (int) $porTipo->json('data.0.pedido_linea_id'));

        $porLinea = $this->historial($receptor, $pedido, ['linea_id' => $linea->linea_id])->assertOk();

        foreach ($porLinea->json('data') as $evento) {
            $this->assertSame($linea->linea_id, (int) $evento['pedido_linea_id']);
        }
    }

    public function test_historial_de_uuid_ajeno_es_404_y_sin_lectura_es_403(): void
    {
        [$pedido] = $this->pedidoConNovedadAbierta();

        $this->historial($this->usuario('contabilidad'), $pedido, [])->assertOk();

        // UUID inexistente: 404.
        $this->getJson('/api/web/inventory-orders/'.Str::uuid().'/history')
            ->assertStatus(404);

        // Rol sin capacidad de lectura: 403.
        $this->historial($this->usuario('operativo'), $pedido, [])->assertStatus(403);
    }

    public function test_historial_no_expone_secretos_de_evidencia(): void
    {
        [$pedido, $receptor, $linea, $novedad] = $this->pedidoConNovedadAbierta();

        PedidoEvento::create([
            'pedido_id' => $pedido->pedido_id,
            'pedido_linea_id' => $linea->linea_id,
            'event_type' => 'evento_con_metadatos_privados',
            'actor_user_id' => $receptor->id,
            'actor_rol_snapshot' => 'auxiliar_bodega',
            'datos_antes' => null,
            'datos_despues' => [
                'novedad_id' => (int) $novedad->novedad_id,
                'documento' => [
                    'documento_id' => 1,
                    'storage_key' => 'pedidos/privado/original.jpg',
                    'url' => 'https://privado.test/firma',
                    'path' => '/var/www/privado',
                ],
                'token' => 'no-debe-salir',
            ],
        ]);

        $data = $this->historial($receptor, $pedido, ['tipo' => 'evento_con_metadatos_privados'])
            ->assertOk()
            ->json('data.0.datos_despues');

        $this->assertSame((int) $novedad->novedad_id, (int) $data['novedad_id']);
        $this->assertArrayNotHasKey('storage_key', $data['documento']);
        $this->assertArrayNotHasKey('url', $data['documento']);
        $this->assertArrayNotHasKey('path', $data['documento']);
        $this->assertArrayNotHasKey('token', $data);
    }
}
