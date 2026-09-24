<?php

namespace Tests\Feature\InventoryOrders;

use App\Events\InventoryOrders\PedidoAlertaEmitida;
use App\Events\NotificationSent;
use App\Models\Notificacion;
use App\Models\PedidoAlerta;
use App\Models\PedidoEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\InventoryOrders\Concerns\PedidoPublicacionFixtures;
use Tests\TestCase;

/**
 * Realtime opt-in U11 (plan sección 12): entrega privada de `pedido_alertas`
 * detrás del flag `inventory_orders.realtime.enabled` (default OFF).
 *
 * Garantías bajo prueba:
 * - Habilitado: un evento `PedidoAlertaEmitida` (encolado) por alerta creada.
 * - Apagado: cero cambios de comportamiento (la bandeja sigue funcionando).
 * - Payload mínimo y canal privado por destinatario con capacidad explícita.
 * - Cero contaminación legacy: sin filas `Notificacion`, sin `NotificationSent`,
 *   sin FCM.
 *
 * Notas de infraestructura:
 * - No existe `Broadcast::fake()` en este Laravel: se usa `Event::fake()`
 *   acotado a las clases bajo prueba (el resto del dispatcher sigue real para
 *   no romper los model events de la publicación).
 * - Los drivers `null`/`log` no ejecutan los callbacks de canal en
 *   `/broadcasting/auth`; los tests de autorización usan el driver `pusher`
 *   con credenciales ficticias (la firma de auth es HMAC local, sin red).
 */
class PedidoAlertaRealtimeTest extends TestCase
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

    private function usarDriverPusherFalso(): void
    {
        // Los callbacks de canal se registran en el broadcaster del driver por
        // defecto al arrancar (en pruebas: `null`). Al cambiar a `pusher` hay
        // que purgar y re-registrar para que /broadcasting/auth vea el canal.
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher' => [
                'driver' => 'pusher',
                'key' => 'u11-test-key',
                'secret' => 'u11-test-secret',
                'app_id' => 'u11-test-app',
                'options' => ['cluster' => 'mt1', 'useTLS' => false],
            ],
        ]);

        Broadcast::purge();

        require base_path('routes/channels.php');
    }

    private function authCanal(int $userId, string $channel): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }

    public function test_emite_un_evento_por_alerta_creada_cuando_habilitado(): void
    {
        config(['inventory_orders.realtime.enabled' => true]);
        Event::fake([PedidoAlertaEmitida::class, NotificationSent::class]);

        $actor = $this->usuario('contabilidad');
        $receptorA = $this->receptorHabilitado();
        $receptorB = $this->receptorHabilitado();

        $pedido = $this->pedidoPublicable($actor);
        $operacion = (string) Str::uuid();

        $this->publicarPedido($pedido, $actor, [
            'client_operation_id' => $operacion,
            'version' => 1,
        ])->assertOk();

        $this->assertSame(2, PedidoAlerta::query()->count());

        Event::assertDispatchedTimes(PedidoAlertaEmitida::class, 2);

        // El replay idempotente no re-crea alertas y por tanto no re-emite.
        $this->publicarPedido($pedido->fresh(), $actor, [
            'client_operation_id' => $operacion,
            'version' => 1,
        ])->assertOk()->assertJsonPath('idempotent_replay', true);

        Event::assertDispatchedTimes(PedidoAlertaEmitida::class, 2);
        $this->assertSame(2, PedidoAlerta::query()->count());
    }

    public function test_apagado_no_despacha_nada_y_la_bandeja_sigue_funcionando(): void
    {
        config(['inventory_orders.realtime.enabled' => false]);
        Event::fake([PedidoAlertaEmitida::class, NotificationSent::class]);

        $actor = $this->usuario('contabilidad');
        $receptor = $this->receptorHabilitado();

        $pedido = $this->pedidoPublicable($actor);
        $this->publicarPedido($pedido, $actor)->assertOk();

        Event::assertNotDispatched(PedidoAlertaEmitida::class);

        // Cero cambio de comportamiento: las alertas persisten igual.
        $this->assertSame(1, PedidoAlerta::query()->count());

        Sanctum::actingAs($receptor);

        $this->getJson('/api/web/inventory-order-alerts')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 1);
    }

    public function test_payload_minimo_y_canal_privado_por_destinatario(): void
    {
        config(['inventory_orders.realtime.enabled' => true]);

        $actor = $this->usuario('contabilidad');
        $receptor = $this->receptorHabilitado();

        $pedido = $this->pedidoPublicable($actor);
        $this->publicarPedido($pedido, $actor)->assertOk();

        $alerta = PedidoAlerta::query()
            ->where('destinatario_user_id', $receptor->id)
            ->firstOrFail();
        $evento = PedidoEvento::query()
            ->where('event_uuid', $alerta->event_uuid)
            ->firstOrFail();

        $emitido = new PedidoAlertaEmitida($alerta->load('pedido'), $evento);

        $this->assertSame('pedido.alerta', $emitido->broadcastAs());

        $payload = $emitido->broadcastWith();

        $this->assertSame(
            ['alert_id', 'pedido_id', 'pedido_uuid', 'tipo', 'revision'],
            array_keys($payload),
        );
        $this->assertSame($alerta->alerta_id, $payload['alert_id']);
        $this->assertSame($alerta->pedido_id, $payload['pedido_id']);
        $this->assertSame($pedido->uuid, $payload['pedido_uuid']);
        $this->assertSame('pedido_publicado', $payload['tipo']);
        $this->assertSame($evento->revision, $payload['revision']);

        // Sin fugas: nada de nombres, facturas, OCR ni tokens en el payload.
        $serializado = json_encode($payload);
        $this->assertStringNotContainsString('PROVEEDOR', (string) $serializado);
        $this->assertStringNotContainsString('factura', strtolower((string) $serializado));

        $canales = $emitido->broadcastOn();
        $this->assertCount(1, $canales);
        $this->assertSame('private-pedidos.alertas.'.$receptor->id, $canales[0]->name);
    }

    public function test_canal_autoriza_al_dueno_con_capacidad(): void
    {
        $this->usarDriverPusherFalso();

        $receptor = $this->receptorHabilitado();

        Sanctum::actingAs($receptor);

        $this->authCanal($receptor->id, 'private-pedidos.alertas.'.$receptor->id)
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_canal_niega_a_otro_usuario(): void
    {
        $this->usarDriverPusherFalso();

        $dueno = $this->receptorHabilitado();
        $otro = $this->receptorHabilitado();

        Sanctum::actingAs($otro);

        $this->authCanal($otro->id, 'private-pedidos.alertas.'.$dueno->id)
            ->assertForbidden();
    }

    public function test_canal_niega_sin_capacidad_aunque_el_id_coincida(): void
    {
        $this->usarDriverPusherFalso();

        $sinCapacidad = $this->usuario('mecanico');

        Sanctum::actingAs($sinCapacidad);

        $this->authCanal($sinCapacidad->id, 'private-pedidos.alertas.'.$sinCapacidad->id)
            ->assertForbidden();
    }

    public function test_sin_contaminacion_legacy_ni_fcm(): void
    {
        config(['inventory_orders.realtime.enabled' => true]);
        Event::fake([PedidoAlertaEmitida::class, NotificationSent::class]);

        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();

        $this->publicarPedido($this->pedidoPublicable($actor), $actor)->assertOk();

        Event::assertNotDispatched(NotificationSent::class);
        $this->assertSame(0, Notificacion::query()->count());
    }
}
