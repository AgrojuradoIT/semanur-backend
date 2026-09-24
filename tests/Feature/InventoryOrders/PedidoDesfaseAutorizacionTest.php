<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\PedidoEstado;
use App\Events\NotificationSent;
use App\Models\PedidoAlerta;
use App\Models\PedidoAutorizacion;
use App\Models\PedidoEvento;
use App\Models\PedidoNovedad;
use App\Models\TransaccionInventario;
use App\Services\Inventory\Orders\DesfaseContexto;
use App\Services\Inventory\Orders\PedidoDesfaseService;
use App\Services\Inventory\Orders\PedidoSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\InventoryOrders\Concerns\PedidoDesfaseFixtures;
use Tests\TestCase;

/**
 * Firma de desfase (U7; R07/R10/R11 y D3).
 *
 * La firma por sí sola NUNCA mueve stock; el actor sale del auth; los campos
 * monetarios están prohibidos y las columnas monetarias quedan NULL.
 */
class PedidoDesfaseAutorizacionTest extends TestCase
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

    public function test_d1_habilitado_por_defecto_solo_para_auxiliar_de_bodega(): void
    {
        $this->assertTrue((bool) config('inventory_orders.discrepancy_authorization_enabled'));
        $this->assertSame(['auxiliar_bodega'], config('inventory_orders.discrepancy_authorizer_roles'));

        [$pedido, $receptor, , $novedad] = $this->pedidoConNovedadAbierta();

        $respuesta = $this->autorizar($receptor, $pedido, $novedad->novedad_id)->assertOk();

        $respuesta->assertJsonPath('data.autorizacion.estado', PedidoAutorizacion::ESTADO_VIGENTE)
            ->assertJsonPath('data.autorizacion.cantidad_autorizada', '2.00')
            ->assertJsonPath('data.autorizacion.diferencia_cantidad', '-2.00')
            ->assertJsonPath('data.autorizacion.importe', null)
            ->assertJsonPath('data.autorizacion.moneda', null);
    }

    public function test_firma_vigente_no_mueve_stock_y_avisa_a_contabilidad_y_receptor(): void
    {
        Event::fake([NotificationSent::class]);

        $creador = $this->usuario('contabilidad');
        [$pedido, $receptor, $linea, $novedad] = $this->pedidoConNovedadAbierta(creador: $creador);

        // Primera confirmación: solo la línea conforme ingresó.
        $this->assertDatabaseCount('transaccion_inventarios', 1);

        $respuesta = $this->autorizar($receptor, $pedido, $novedad->novedad_id)->assertOk();
        $autorizacionId = (int) $respuesta->json('data.autorizacion.autorizacion_id');

        // Firmar no ingresa la cantidad firmada.
        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame('novedad', $linea->fresh()->estado);
        $this->assertSame('parcial_con_novedad', $pedido->fresh()->estado);

        $autorizacion = PedidoAutorizacion::query()->findOrFail($autorizacionId);

        $this->assertTrue($autorizacion->estaVigente());
        $this->assertSame('2.00', PedidoSnapshot::cantidadCanonica($autorizacion->cantidad_autorizada));
        $this->assertSame(-2.0, (float) $autorizacion->diferencia_cantidad);
        $this->assertSame((int) $receptor->id, (int) $autorizacion->actor_user_id);
        $this->assertSame('auxiliar_bodega', $autorizacion->rol_snapshot);
        $this->assertNull($autorizacion->importe);
        $this->assertNull($autorizacion->precio_unitario_confirmado);
        $this->assertNull($autorizacion->moneda);
        $this->assertNull($autorizacion->base_precio);
        $this->assertSame(2, (int) $autorizacion->count_revision);
        $this->assertNotNull($autorizacion->aceptada_at);
        $this->assertStringContainsString('v1|', (string) $autorizacion->aceptacion_responsabilidad);

        // Evento con snapshots E/C/A/condición/tipo y hash de contexto.
        $evento = PedidoEvento::query()->where('event_type', 'desfase_autorizado')->sole();
        $this->assertSame((int) $receptor->id, (int) $evento->actor_user_id);
        $this->assertSame('2.00', $evento->datos_despues['aptos']);
        $this->assertSame('4.00', $evento->datos_despues['esperado']);
        $this->assertSame('3.00', $evento->datos_despues['contado']);
        $this->assertSame('conforme', $evento->datos_despues['estado_condicion']);
        $this->assertSame('faltante', $evento->datos_despues['tipo_novedad']);
        $this->assertSame('v1', $evento->datos_despues['responsabilidad_version']);
        $this->assertNull($evento->datos_despues['moneda']);

        // Alertas: contabilidad + receptor firmante; cero FCM/legacy.
        $alertas = PedidoAlerta::query()->where('tipo', 'desfase_autorizado')->get();
        $this->assertCount(2, $alertas);
        $this->assertTrue($alertas->contains('destinatario_user_id', (int) $creador->id));
        $this->assertTrue($alertas->contains('destinatario_user_id', (int) $receptor->id));
        $this->assertDatabaseCount('notificaciones', 0);
        Event::assertNotDispatched(NotificationSent::class);
    }

    public function test_contexto_hash_se_liga_a_la_revision_vigente(): void
    {
        [$pedido, $receptor, $linea, $novedad] = $this->pedidoConNovedadAbierta();

        $motivo = 'Faltante verificado con evidencia fotográfica';
        $respuesta = $this->autorizar($receptor, $pedido, $novedad->novedad_id, ['motivo' => $motivo])->assertOk();

        $autorizacion = PedidoAutorizacion::query()->findOrFail((int) $respuesta->json('data.autorizacion.autorizacion_id'));
        $observacion = PedidoNovedad::query()->findOrFail($novedad->novedad_id)->recepcionLinea;

        $contexto = DesfaseContexto::capturar(
            $pedido->fresh(),
            $linea->fresh(),
            $observacion,
        );

        $this->assertSame(DesfaseContexto::hash($contexto), $autorizacion->contexto_hash);
        $this->assertSame($pedido->fresh()->versionActual(), (int) $autorizacion->order_version);
        $this->assertSame($linea->fresh()->versionActual(), (int) $autorizacion->line_version);
    }

    public function test_d1_deshabilitado_por_env_bloquea_la_firma_sin_registros(): void
    {
        [$pedido, $receptor, , $novedad] = $this->pedidoConNovedadAbierta();

        $this->configurarD1(habilitado: false);

        $this->autorizar($receptor, $pedido, $novedad->novedad_id)->assertStatus(403);

        $this->assertDatabaseCount('pedido_autorizaciones', 0);
        $this->assertSame(1, TransaccionInventario::query()->count());
    }

    public function test_contabilidad_y_admin_no_firman_y_los_campos_de_actor_se_rechazan(): void
    {
        $creador = $this->usuario('contabilidad');
        [$pedido, $receptor, , $novedad] = $this->pedidoConNovedadAbierta(creador: $creador);

        $contabilidad = $this->usuario('contabilidad');
        $this->empleado(['user_id' => $contabilidad->id]);

        $admin = $this->usuario('admin');
        $this->empleado(['user_id' => $admin->id]);

        // Roles fuera de discrepancy_authorizer_roles: 403 aunque tengan empleado.
        $this->autorizar($contabilidad, $pedido, $novedad->novedad_id)->assertStatus(403);
        $this->autorizar($admin, $pedido, $novedad->novedad_id)->assertStatus(403);

        // Campos de actor/falsificación: 422 y cero filas.
        foreach ([
            'actor_user_id' => 999,
            'usuario_id' => 999,
            'authorized_by' => 999,
            'empleado_id' => 999,
        ] as $campo => $valor) {
            $this->autorizar($receptor, $pedido, $novedad->novedad_id, [$campo => $valor])
                ->assertStatus(422)
                ->assertJsonValidationErrors($campo);
        }

        $this->assertDatabaseCount('pedido_autorizaciones', 0);
    }

    public function test_d3_rechaza_campos_monetarios_y_las_columnas_quedan_null(): void
    {
        [$pedido, $receptor, , $novedad] = $this->pedidoConNovedadAbierta();

        foreach ([
            'importe' => '75000.00',
            'precio_unitario_confirmado' => '25000.00',
            'moneda' => 'COP',
            'base_precio' => 'sin_iva',
        ] as $campo => $valor) {
            $this->autorizar($receptor, $pedido, $novedad->novedad_id, [$campo => $valor])
                ->assertStatus(422)
                ->assertJsonValidationErrors($campo);
        }

        $this->assertDatabaseCount('pedido_autorizaciones', 0);

        // Sin intento monetario, la firma se guarda con columnas NULL.
        $this->autorizar($receptor, $pedido, $novedad->novedad_id)->assertOk();

        $autorizacion = PedidoAutorizacion::query()->sole();
        $this->assertNull($autorizacion->importe);
        $this->assertNull($autorizacion->precio_unitario_confirmado);
        $this->assertNull($autorizacion->moneda);
        $this->assertNull($autorizacion->base_precio);
    }

    public function test_sobrante_es_422_y_a_cero_queda_bloqueado_sin_politica(): void
    {
        // A=5 sobre E=4: sobrante.
        [$pedido, $receptor, , $novedad] = $this->pedidoConNovedadAbierta(contado: '5.00', aptos: '5.00');

        $this->autorizar($receptor, $pedido, $novedad->novedad_id)
            ->assertStatus(422)
            ->assertJsonPath('cantidad_esperada', '4.00')
            ->assertJsonPath('conteo_aptos', '5.00');

        $this->assertDatabaseCount('pedido_autorizaciones', 0);
        $this->assertSame('abierta', $novedad->fresh()->estado);

        // A=0 sin política habilitada: bloqueado y la novedad sigue abierta.
        [$pedidoCero, $receptorCero, , $novedadCero] = $this->pedidoConNovedadAbierta(contado: '0.00', aptos: '0.00');

        $this->autorizar($receptorCero, $pedidoCero, $novedadCero->novedad_id)
            ->assertStatus(422)
            ->assertJsonPath('cierre_sin_ingreso_permitido', false);

        $this->assertDatabaseCount('pedido_autorizaciones', 0);
        $this->assertSame('abierta', $novedadCero->fresh()->estado);
    }

    public function test_cierre_sin_ingreso_con_politica_registra_q_cero_sin_stock(): void
    {
        $this->configurarD1(cierreSinIngreso: true);

        [$pedido, $receptor, $linea, $novedad] = $this->pedidoConNovedadAbierta(contado: '0.00', aptos: '0.00');

        $respuesta = $this->autorizar($receptor, $pedido, $novedad->novedad_id)->assertOk();

        $respuesta->assertJsonPath('data.autorizacion.cantidad_autorizada', '0.00')
            ->assertJsonPath('data.autorizacion.diferencia_cantidad', '-4.00');

        $this->assertDatabaseCount('pedido_autorizaciones', 1);
        $this->assertSame(1, TransaccionInventario::query()->count());
        $this->assertSame('novedad', $linea->fresh()->estado);
        $this->assertSame('parcial_con_novedad', $pedido->fresh()->estado);
    }

    public function test_condicion_danada_o_sku_incorrecto_no_se_firma(): void
    {
        [$pedido, $receptor, $linea, $novedad] = $this->pedidoConNovedadAbierta();

        // Reconteo que declara mercancía dañada: la firma no la vuelve apta.
        $segunda = $this->abrirRecepcion($receptor, $pedido)->assertStatus(201);

        $this->contar($receptor, $pedido, (int) $segunda->json('data.recepcion_id'), $linea, [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'danado',
            'motivo' => 'Empaque roto en todas las unidades',
        ])->assertOk();

        $this->autorizar($receptor, $pedido, $novedad->novedad_id)
            ->assertStatus(422)
            ->assertJsonPath('estado_condicion', 'danado');

        $this->assertDatabaseCount('pedido_autorizaciones', 0);
    }

    public function test_una_nueva_firma_reemplaza_la_anterior_con_auditoria(): void
    {
        [$pedido, $receptor, , $novedad] = $this->pedidoConNovedadAbierta();

        $primera = $this->autorizar($receptor, $pedido, $novedad->novedad_id, [
            'motivo' => 'Primera firma de prueba con motivo largo',
        ])->assertOk();

        $segunda = $this->autorizar($receptor, $pedido, $novedad->novedad_id, [
            'motivo' => 'Segunda firma corregida con motivo largo',
        ])->assertOk();

        $idPrimera = (int) $primera->json('data.autorizacion.autorizacion_id');
        $idSegunda = (int) $segunda->json('data.autorizacion.autorizacion_id');

        $this->assertSame(PedidoAutorizacion::ESTADO_INVALIDADA, PedidoAutorizacion::query()->findOrFail($idPrimera)->estado);
        $this->assertSame(PedidoAutorizacion::ESTADO_VIGENTE, PedidoAutorizacion::query()->findOrFail($idSegunda)->estado);
        $this->assertSame(1, PedidoAutorizacion::query()->where('estado', PedidoAutorizacion::ESTADO_VIGENTE)->count());

        $invalidacion = PedidoEvento::query()->where('event_type', 'desfase_invalidado')->sole();
        $this->assertSame('reemplazada_por_nueva_firma', $invalidacion->datos_despues['motivo']);
        $this->assertSame($idPrimera, (int) $invalidacion->datos_despues['autorizacion_id']);
    }

    public function test_novedad_de_otro_pedido_es_404(): void
    {
        [$pedido, $receptor, , $novedad] = $this->pedidoConNovedadAbierta();
        [, , , $novedadAjena] = $this->pedidoConNovedadAbierta();

        $this->autorizar($receptor, $pedido, $novedadAjena->novedad_id)->assertStatus(404);
        $this->autorizar($receptor, $pedido, $novedad->novedad_id)->assertOk();
    }

    public function test_receptor_sin_empleado_activo_no_firma(): void
    {
        [$pedido, , , $novedad] = $this->pedidoConNovedadAbierta();

        $sinEmpleado = $this->usuario('auxiliar_bodega');

        $this->autorizar($sinEmpleado, $pedido, $novedad->novedad_id)->assertStatus(403);
        $this->assertDatabaseCount('pedido_autorizaciones', 0);
    }

    public function test_confirmar_inmediato_firma_y_recibe_en_una_sola_accion(): void
    {
        $creador = $this->usuario('contabilidad');
        [$pedido, $receptor, $linea, $novedad] = $this->pedidoConNovedadAbierta(creador: $creador);

        $respuesta = $this->autorizar($receptor, $pedido, $novedad->novedad_id, [
            'confirmar_inmediato' => true,
        ])->assertOk();

        $respuesta->assertJsonPath('data.confirmacion.resulting_state', PedidoEstado::RECIBIDO_CON_DESFASE)
            ->assertJsonPath('data.confirmacion.posted_lines.0.quantity', '2.00');

        $autorizacion = PedidoAutorizacion::query()->sole();
        $this->assertSame(PedidoAutorizacion::ESTADO_CONSUMIDA, $autorizacion->estado);
        $this->assertNotNull($autorizacion->consumida_at);

        $this->assertSame('recibida_con_desfase', $linea->fresh()->estado);
        $this->assertSame('recibido_con_desfase', $pedido->fresh()->estado);
        $this->assertSame('cerrada', $novedad->fresh()->estado);

        $transacciones = TransaccionInventario::query()->orderBy('transaccion_id')->get();
        $this->assertCount(2, $transacciones);
        $this->assertSame('2.00', PedidoSnapshot::cantidadCanonica($transacciones->last()->transaccion_cantidad));
        $this->assertSame((int) $receptor->id, (int) $transacciones->last()->usuario_id);
        $this->assertSame(1, PedidoEvento::query()->where('event_type', 'recibido_con_desfase')->count());
        $this->assertSame(1, PedidoAlerta::query()->where('tipo', 'recibido_con_desfase')->count());
    }

    public function test_confirmar_inmediato_hace_rollback_total_ante_fallo_inducido(): void
    {
        [$pedido, $receptor, $linea, $novedad] = $this->pedidoConNovedadAbierta();

        TransaccionInventario::creating(function (TransaccionInventario $transaccion) use ($linea): void {
            if ((int) $transaccion->pedido_linea_id === (int) $linea->linea_id) {
                throw new RuntimeException('Fallo inducido en la recepción combinada.');
            }
        });

        $servicio = $this->app->make(PedidoDesfaseService::class);

        try {
            $servicio->autorizar(
                $pedido,
                $novedad->novedad_id,
                $receptor,
                'Firma con recepción que debe revertirse completa',
                true,
            );

            $this->fail('La recepción combinada debía fallar.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo inducido en la recepción combinada.', $exception->getMessage());
        }

        // NADA sobrevive: ni firma, ni ledger nuevo, ni evento/alerta, ni estado.
        $this->assertDatabaseCount('pedido_autorizaciones', 0);
        $this->assertSame(1, TransaccionInventario::query()->count());
        $this->assertSame(0, PedidoEvento::query()->where('event_type', 'desfase_autorizado')->count());
        $this->assertSame(0, PedidoAlerta::query()->where('tipo', 'desfase_autorizado')->count());
        $this->assertSame('novedad', $linea->fresh()->estado);
        $this->assertSame('parcial_con_novedad', $pedido->fresh()->estado);
    }
}
