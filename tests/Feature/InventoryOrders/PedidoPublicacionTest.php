<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\OperacionRechazadaException;
use App\Domain\InventoryOrders\PedidoEstado;
use App\Events\NotificationSent;
use App\Models\Notificacion;
use App\Models\PedidoAlerta;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoOperacion;
use App\Services\Inventory\Orders\PedidoDocumentoService;
use App\Services\Inventory\Orders\PedidoPublicacionService;
use App\Services\Inventory\Orders\PedidoRetiroCancelacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\InventoryOrders\Concerns\PedidoPublicacionFixtures;
use Tests\TestCase;

/**
 * Publicación U5: puertas R02 con mensajes distintos, efectos atómicos,
 * congelamiento del esperado, alertas por receptor e idempotencia. Cero
 * movimientos de inventario y cero contaminación del pipeline legacy.
 */
class PedidoPublicacionTest extends TestCase
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

    public function test_publicacion_feliz_congela_esperado_genera_numero_y_una_alerta_por_receptor(): void
    {
        $actor = $this->usuario('contabilidad');
        $receptorUno = $this->receptorHabilitado();
        $receptorDos = $this->receptorHabilitado();
        $inactivo = $this->receptorHabilitado();
        $this->empleado(['estado' => 'retirado', 'user_id' => $inactivo->id]);

        $pedido = $this->pedidoPublicable($actor, [
            'proveedor_clave' => 'proveedor x',
            'referencia_factura' => 'fv 001',
        ]);

        $respuesta = $this->publicarPedido($pedido, $actor)->assertOk();

        $respuesta->assertJsonPath('success', true)
            ->assertJsonPath('idempotent_replay', false)
            ->assertJsonPath('data.estado', PedidoEstado::PENDIENTE_RECEPCION)
            ->assertJsonPath('data.numero', sprintf('PED-%06d', $pedido->pedido_id))
            ->assertJsonPath('data.identidad_documental_reservada', true)
            ->assertJsonPath('data.receptores_notificados', 2);

        $fresco = $pedido->fresh();

        $this->assertSame(PedidoEstado::PENDIENTE_RECEPCION, $fresco->estado);
        $this->assertSame($actor->id, (int) $fresco->submitted_by);
        $this->assertNotNull($fresco->published_at);
        $this->assertSame('PROVEEDOR X|FV 001', $fresco->clave_documental_normalizada);
        $this->assertSame(2, $fresco->versionActual());
        $this->assertSame(
            0,
            PedidoInventarioLinea::query()->where('pedido_id', $pedido->pedido_id)->whereNull('esperado_congelado_at')->count(),
        );

        $evento = PedidoEvento::query()->where('event_type', 'pedido_publicado')->firstOrFail();
        $this->assertSame('pendiente', $evento->estado_despacho);
        $this->assertSame($actor->id, $evento->actor_user_id);
        $this->assertSame('PROVEEDOR X|FV 001', $evento->datos_despues['clave_documental_normalizada']);

        $this->assertSame(2, PedidoAlerta::query()->where('tipo', 'pedido_publicado')->count());
        $this->assertTrue(PedidoAlerta::query()->where('destinatario_user_id', $receptorUno->id)->exists());
        $this->assertTrue(PedidoAlerta::query()->where('destinatario_user_id', $receptorDos->id)->exists());
        $this->assertFalse(PedidoAlerta::query()->where('destinatario_user_id', $inactivo->id)->exists());

        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('bodega_producto', 0);
        $this->assertDatabaseCount('notificaciones', 0);
    }

    public function test_publicacion_es_idempotente_por_client_operation_id(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($actor);
        $operacion = (string) Str::uuid();

        $primera = $this->publicarPedido($pedido, $actor, [
            'client_operation_id' => $operacion,
            'version' => 1,
        ])->assertOk();

        $replay = $this->publicarPedido($pedido->fresh(), $actor, [
            'client_operation_id' => $operacion,
            'version' => 1,
        ])->assertOk();

        $replay->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('data.numero', $primera->json('data.numero'));

        $this->assertDatabaseCount('pedido_operaciones', 1);
        $this->assertSame(1, PedidoEvento::query()->where('event_type', 'pedido_publicado')->count());
        $this->assertSame(1, PedidoAlerta::query()->where('tipo', 'pedido_publicado')->count());
    }

    public function test_mismo_client_operation_con_payload_distinto_es_409(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($actor);
        $operacion = (string) Str::uuid();

        $this->publicarPedido($pedido, $actor, [
            'client_operation_id' => $operacion,
            'version' => 1,
        ])->assertOk();

        $this->publicarPedido($pedido->fresh(), $actor, [
            'client_operation_id' => $operacion,
            'version' => 1,
            'motivo_compra_distinta' => 'Compra realmente distinta',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'client_operation_id ya fue utilizado con un contenido diferente.');

        $this->assertSame(1, PedidoEvento::query()->where('event_type', 'pedido_publicado')->count());
    }

    public function test_puerta_proveedor_no_confirmado(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($actor, ['proveedor_nombre_confirmado' => null]);

        $this->publicarPedido($pedido, $actor)
            ->assertStatus(422)
            ->assertJsonValidationErrors('proveedor_nombre_confirmado');

        $this->assertSame(
            'Debes confirmar el nombre del proveedor antes de publicar.',
            $this->publicarPedido($pedido, $actor)->json('errors.proveedor_nombre_confirmado.0'),
        );
    }

    public function test_puerta_sin_linea_valida(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($actor, [], ['sku_confirmado' => null]);

        $this->publicarPedido($pedido, $actor)
            ->assertStatus(422)
            ->assertJsonValidationErrors('lineas');
    }

    public function test_puerta_falta_pantallazo_siigo(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoConRoles([PedidoDocumentoService::ROL_FACTURA]);

        $this->publicarPedido($pedido, $actor)
            ->assertStatus(422)
            ->assertJsonValidationErrors('documentos');
    }

    public function test_puerta_falta_factura(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoConRoles([PedidoDocumentoService::ROL_PANTALLAZO_SIIGO]);

        $this->publicarPedido($pedido, $actor)
            ->assertStatus(422)
            ->assertJsonValidationErrors('factura');
    }

    public function test_puerta_bodega_invalida(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($actor, ['bodega_id' => null]);

        $this->publicarPedido($pedido, $actor)
            ->assertStatus(422)
            ->assertJsonValidationErrors('bodega_id');
    }

    public function test_puerta_sin_receptor_habilitado_es_error_operativo_409(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedidoPublicable($actor);

        $respuesta = $this->publicarPedido($pedido, $actor)->assertStatus(409);

        $this->assertStringContainsString('sin receptor habilitado, publicar falla', (string) $respuesta->json('message'));
        $this->assertSame(PedidoEstado::BORRADOR, $pedido->fresh()->estado);
    }

    public function test_publicacion_requiere_capacidad_y_estado_borrador(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($actor);

        $this->publicarPedido($pedido, $this->usuario('auxiliar_bodega'))->assertStatus(403);
        $this->publicarPedido($pedido, $this->usuario('mecanico'))->assertStatus(403);
        $this->publicarPedido($pedido, $this->usuario('jefe_taller'))->assertStatus(403);

        $this->publicarPedido($pedido, $actor)->assertOk();

        $this->publicarPedido($pedido->fresh(), $actor)
            ->assertStatus(409)
            ->assertJsonPath('message', 'El pedido ya no está en borrador y no puede publicarse.');
    }

    public function test_version_desactualizada_es_409_con_version_vigente(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($actor);

        $this->publicarPedido($pedido, $actor, ['version' => 99])
            ->assertStatus(409)
            ->assertJsonPath('current_version', 1);
    }

    public function test_sin_identidad_fiable_no_se_reserva_clave(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable($actor, ['proveedor_clave' => null]);

        $this->publicarPedido($pedido, $actor)
            ->assertOk()
            ->assertJsonPath('data.identidad_documental_reservada', false);

        $this->assertNull($pedido->fresh()->clave_documental_normalizada);
    }

    public function test_el_servicio_revalida_estado_bajo_lock_con_mensaje_propio(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedidoPublicable($actor);

        $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION);

        try {
            app(PedidoPublicacionService::class)->publicar(
                $pedido->fresh(),
                $actor,
                $pedido->fresh()->versionActual(),
                (string) Str::uuid(),
            );

            $this->fail('La publicación debía rechazarse fuera de borrador.');
        } catch (OperacionRechazadaException $excepcion) {
            $this->assertSame(409, $excepcion->httpStatus);
            $this->assertStringContainsString('borrador', $excepcion->getMessage());
        }
    }

    public function test_flujo_completo_publicar_retirar_republicar_cancelar_no_toca_stock_ni_legacy(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();

        $pedido = $this->pedidoPublicable($actor, [
            'proveedor_clave' => null,
            'referencia_factura' => null,
        ]);

        Event::fake([NotificationSent::class]);

        $publicacion = app(PedidoPublicacionService::class);
        $retiro = app(PedidoRetiroCancelacionService::class);

        $publicacion->publicar($pedido->fresh(), $actor, 1, (string) Str::uuid());
        $retiro->retirar($pedido->fresh(), $actor, 2, 'Error documental en la factura', (string) Str::uuid());
        $publicacion->publicar($pedido->fresh(), $actor, 3, (string) Str::uuid());
        $retiro->cancelar($pedido->fresh(), $actor, 4, 'Compra anulada por el proveedor', (string) Str::uuid());

        $this->assertSame(PedidoEstado::CANCELADO, $pedido->fresh()->estado);
        $this->assertSame(2, PedidoEvento::query()->where('event_type', 'pedido_publicado')->count());
        $this->assertSame(1, PedidoEvento::query()->where('event_type', 'pedido_retirado')->count());
        $this->assertSame(1, PedidoEvento::query()->where('event_type', 'pedido_cancelado')->count());

        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('bodega_producto', 0);
        $this->assertSame(0, Notificacion::query()->count());
        Event::assertNotDispatched(NotificationSent::class);

        Sanctum::actingAs($this->usuario('admin'));
        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }

    /**
     * @param  list<string>  $roles
     */
    private function pedidoConRoles(array $roles): PedidoInventario
    {
        $pedido = PedidoInventario::create([
            'bodega_id' => $this->bodega()->bodega_id,
            'created_by' => $this->usuario('contabilidad')->id,
            'proveedor_nombre_confirmado' => 'PROVEEDOR CONFIRMADO',
        ]);

        $this->lineaConfirmada($pedido);

        foreach ($roles as $rol) {
            $this->documentoCompleto($pedido, $rol);
        }

        return $pedido->fresh(['lineas', 'documentos']);
    }
}
