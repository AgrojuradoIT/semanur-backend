<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\PedidoEstado;
use App\Events\NotificationSent;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoNovedad;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\InventoryOrders\Concerns\PedidoRecepcionFixtures;
use Tests\TestCase;

/**
 * Sesión de recepción, conteo (R04/R05/R06) y ficha de catálogo (U6).
 *
 * Contar, abrir sesión o completar ficha NUNCA toca el ledger ni el catálogo.
 */
class PedidoRecepcionConteoTest extends TestCase
{
    use PedidoRecepcionFixtures, RefreshDatabase;

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

    public function test_sesion_de_recepcion_se_crea_una_vez_y_devuelve_la_vigente(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-C-1'],
            ['sku' => 'SKU-C-2', 'cantidad' => '2.00'],
        ]);

        $this->assertDatabaseCount('pedido_recepciones', 1);
        $this->assertDatabaseCount('pedido_recepcion_lineas', 2);

        $segunda = $this->abrirRecepcion($receptor, $pedido)->assertOk();

        $segunda->assertJsonPath('created', false)
            ->assertJsonPath('data.recepcion_id', $recepcion['recepcion_id'])
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.lineas.0.observacion.conteo_total', null);

        $this->assertDatabaseCount('pedido_recepciones', 1);
        $this->assertSame(
            (int) $receptor->empleado->id,
            (int) $recepcion['receptor_empleado_id'],
        );
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_conteo_no_mueve_stock_y_transiciona_el_pedido_a_en_validacion(): void
    {
        Event::fake([NotificationSent::class]);

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-C-1'],
        ]);

        $linea = $this->lineaDe($pedido, 1);

        $respuesta = $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $linea, [
            'conteo_total' => '4',
            'conteo_aptos' => '4',
            'estado_condicion' => 'conforme',
        ]);

        $respuesta->assertOk()
            ->assertJsonPath('data.conteo_total', '4.00')
            ->assertJsonPath('data.conteo_aptos', '4.00')
            ->assertJsonPath('data.estado_condicion', 'conforme')
            ->assertJsonPath('data.observacion_version', 2)
            ->assertJsonPath('data.count_confirmed', false);

        $this->assertSame(PedidoEstado::EN_VALIDACION, $pedido->fresh()->estado);
        $this->assertSame('pendiente', $linea->fresh()->estado);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('bodega_producto', 0);
        $this->assertDatabaseCount('productos', 0);
        Event::assertNotDispatched(NotificationSent::class);
    }

    public function test_null_es_no_contado_y_cero_es_ausencia_explicita(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-C-1'],
        ]);

        $linea = $this->lineaDe($pedido, 1);

        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $linea, [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'conforme',
        ])->assertOk();

        // Descontar: null = no contado, no un cero implícito.
        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $linea, [
            'observacion_version' => 2,
            'conteo_total' => null,
            'conteo_aptos' => null,
            'estado_condicion' => null,
        ])->assertOk()
            ->assertJsonPath('data.conteo_total', null)
            ->assertJsonPath('data.conteo_aptos', null);

        // 0.00 = ausencia explícita; se persiste como cero, no como null.
        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $linea, [
            'observacion_version' => 3,
            'conteo_total' => '0',
            'conteo_aptos' => '0',
            'estado_condicion' => 'conforme',
        ])->assertOk()
            ->assertJsonPath('data.conteo_total', '0.00')
            ->assertJsonPath('data.conteo_aptos', '0.00')
            ->assertJsonPath('data.observacion_version', 4);

        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_aptos_mayor_que_total_es_422(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-C-1'],
        ]);

        $linea = $this->lineaDe($pedido, 1);

        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $linea, [
            'conteo_total' => '3.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'conforme',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('conteo_aptos');

        $this->assertDatabaseCount('pedido_recepcion_lineas', 1);
        $this->assertNull($linea->fresh()->recepcionLineas()->first()->conteo_total);
    }

    public function test_condicion_distinta_de_conforme_exige_motivo(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-C-1'],
        ]);

        $linea = $this->lineaDe($pedido, 1);

        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $linea, [
            'conteo_total' => '4.00',
            'conteo_aptos' => '3.00',
            'estado_condicion' => 'danado',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('motivo');
    }

    public function test_version_de_observacion_desactualizada_es_409_y_conserva_el_conteo(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-C-1'],
        ]);

        $linea = $this->lineaDe($pedido, 1);

        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $linea, [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'conforme',
        ])->assertOk();

        // Segunda pestaña con la versión vieja: 409, sin last-write-wins.
        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $linea, [
            'observacion_version' => 1,
            'conteo_total' => '1.00',
            'conteo_aptos' => '1.00',
            'estado_condicion' => 'conforme',
        ])->assertStatus(409)
            ->assertJsonPath('current_version', 2);

        $observacion = $linea->recepcionLineas()->first();
        $this->assertSame('4.00', \App\Services\Inventory\Orders\PedidoSnapshot::cantidadCanonica($observacion->conteo_total));
    }

    public function test_linea_de_otro_pedido_es_404(): void
    {
        [$pedidoUno, $receptorUno, $recepcionUno] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-C-1'],
        ]);

        [$pedidoDos] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-C-2'],
        ]);

        $lineaAjena = $this->lineaDe($pedidoDos, 1);

        $this->contar($receptorUno, $pedidoUno, $recepcionUno['recepcion_id'], $lineaAjena, [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'conforme',
        ])->assertStatus(404);

        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_recepcion_de_otro_pedido_es_404(): void
    {
        [$pedidoUno, $receptorUno] = $this->pedidoEnRecepcion([['sku' => 'SKU-C-1']]);
        [, , $recepcionDos] = $this->pedidoEnRecepcion([['sku' => 'SKU-C-2']]);

        $linea = $this->lineaDe($pedidoUno, 1);

        $this->contar($receptorUno, $pedidoUno, $recepcionDos['recepcion_id'], $linea, [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'conforme',
        ])->assertStatus(404);
    }

    public function test_ficha_catalogo_se_guarda_en_la_linea_sin_tocar_el_catalogo(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-NUEVO-1'],
        ]);

        $linea = $this->lineaDe($pedido, 1);
        $categoriaId = $this->categoriaId();

        $respuesta = $this->patchJson(
            "/api/web/inventory-orders/{$pedido->uuid}/lines/{$linea->linea_id}/catalog-draft",
            [
                'version' => 1,
                'categoria_propuesta_id' => $categoriaId,
                'alerta_minima_propuesta' => '0',
                'precio_costo_confirmado' => '75.00',
                'unidad' => 'caja',
            ],
        );

        $respuesta->assertOk()
            ->assertJsonPath('data.categoria_propuesta_id', $categoriaId)
            ->assertJsonPath('data.alerta_minima_propuesta', '0.00')
            ->assertJsonPath('data.precio_costo_confirmado', '75.00')
            ->assertJsonPath('data.unidad', 'caja')
            ->assertJsonPath('data.version', 2);

        $this->assertDatabaseCount('productos', 0);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertSame(1, PedidoEvento::query()->where('event_type', 'ficha_catalogo_actualizada')->count());
        $this->assertSame((int) $receptor->id, (int) PedidoEvento::query()->where('event_type', 'ficha_catalogo_actualizada')->value('actor_user_id'));
    }

    public function test_ficha_con_version_desactualizada_es_409(): void
    {
        [$pedido] = $this->pedidoEnRecepcion([['sku' => 'SKU-NUEVO-1']]);
        $linea = $this->lineaDe($pedido, 1);

        $this->patchJson(
            "/api/web/inventory-orders/{$pedido->uuid}/lines/{$linea->linea_id}/catalog-draft",
            ['version' => 99, 'unidad' => 'caja'],
        )->assertStatus(409)
            ->assertJsonPath('current_version', 1);

        $this->assertNull($linea->fresh()->unidad);
    }

    public function test_receptor_sin_empleado_activo_no_puede_abrir_sesion(): void
    {
        [$pedido] = $this->pedidoEnRecepcion([['sku' => 'SKU-C-1']]);

        $sinEmpleado = $this->usuario('auxiliar_bodega');
        $this->abrirRecepcion($sinEmpleado, $pedido)->assertStatus(403);

        $empleadoRetirado = $this->usuario('auxiliar_bodega');
        $this->empleado(['estado' => 'retirado', 'user_id' => $empleadoRetirado->id]);
        $this->abrirRecepcion($empleadoRetirado, $pedido)->assertStatus(403);
    }

    public function test_campos_de_actor_enviados_por_el_cliente_se_rechazan(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([['sku' => 'SKU-C-1']]);
        $linea = $this->lineaDe($pedido, 1);

        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $linea, [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'conforme',
            'actor_user_id' => $receptor->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('actor_user_id');

        $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'], [
            'receptor_empleado_id' => $receptor->empleado->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('receptor_empleado_id');
    }

    public function test_receptor_se_resuelve_del_auth_y_los_campos_del_payload_se_ignoran(): void
    {
        $contador = $this->usuario('contabilidad');
        $forzado = $this->receptorOperativo();

        $pedido = $this->pedidoPublicable($contador);
        $this->publicarPedido($pedido, $contador)->assertOk();

        $receptor = $this->receptorOperativo();
        Sanctum::actingAs($receptor);

        $respuesta = $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/receipts", [
            'receptor_empleado_id' => $forzado->empleado->id,
            'actor_user_id' => $forzado->id,
        ])->assertStatus(201);

        $this->assertSame((int) $receptor->empleado->id, (int) $respuesta->json('data.receptor_empleado_id'));
        $this->assertSame((int) $receptor->id, (int) \App\Models\PedidoRecepcion::query()->value('actor_user_id'));
    }

    public function test_retiro_y_republicacion_invalidan_la_sesion_anterior(): void
    {
        $contador = $this->usuario('contabilidad');

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-REV'],
        ]);

        $linea = $this->lineaDe($pedido, 1);

        // Antes de retirar, la sesión vigente se reutiliza.
        $this->abrirRecepcion($receptor, $pedido)
            ->assertOk()
            ->assertJsonPath('data.recepcion_id', $recepcion['recepcion_id']);

        Sanctum::actingAs($contador);
        $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/return-for-correction", [
            'client_operation_id' => (string) \Illuminate\Support\Str::uuid(),
            'version' => $pedido->fresh()->versionActual(),
            'motivo' => 'Error documental para probar la revisión',
        ])->assertOk();

        $this->publicarPedido($pedido->fresh(), $contador)->assertOk();

        $nueva = $this->abrirRecepcion($receptor, $pedido->fresh())->assertStatus(201);
        $this->assertNotSame((int) $recepcion['recepcion_id'], (int) $nueva->json('data.recepcion_id'));

        // La sesión vieja no admite más conteos ni confirmación.
        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $linea, [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'conforme',
        ])->assertStatus(409)
            ->assertJsonPath('recepcion_invalidada', true);

        $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])
            ->assertStatus(409)
            ->assertJsonPath('recepcion_invalidada', true);

        $this->assertSame(
            \App\Domain\InventoryOrders\RecepcionEstado::INVALIDADA,
            \App\Models\PedidoRecepcion::query()->findOrFail($recepcion['recepcion_id'])->estado,
        );
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_flag_off_oculta_los_endpoints_de_recepcion_con_404(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([['sku' => 'SKU-C-1']]);
        $linea = $this->lineaDe($pedido, 1);

        config(['inventory_orders.enabled' => false]);

        $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/receipts")->assertStatus(404);
        $this->putJson("/api/web/inventory-orders/{$pedido->uuid}/receipts/{$recepcion['recepcion_id']}/lines/{$linea->linea_id}", [])->assertStatus(404);
        $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/receipts/{$recepcion['recepcion_id']}/confirm", [])->assertStatus(404);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_confirmacion_sin_operacion_explicita_no_mueve_stock(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([['sku' => 'SKU-C-1']]);
        $linea = $this->lineaDe($pedido, 1);

        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $linea, [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'conforme',
        ])->assertOk();

        $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'], [
            'client_operation_id' => null,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('client_operation_id');

        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }
}
