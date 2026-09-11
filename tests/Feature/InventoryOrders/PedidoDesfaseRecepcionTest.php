<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\LineaEstado;
use App\Domain\InventoryOrders\NovedadEstado;
use App\Domain\InventoryOrders\PedidoEstado;
use App\Models\PedidoAlerta;
use App\Models\PedidoAutorizacion;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoNovedad;
use App\Models\PedidoRecepcionLinea;
use App\Models\TransaccionInventario;
use App\Services\Inventory\Orders\PedidoSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\InventoryOrders\Concerns\PedidoDesfaseFixtures;
use Tests\TestCase;

/**
 * Consumo e invalidación de firmas dentro de la confirmación (U7).
 *
 * R07: firma válida + confirmar ingresa Q = A y cierra con desfase.
 * R10: A = 0 con política cierra sin ingreso y sin transacción de cero.
 * R12: un conteo nuevo cambia el contexto e invalida la firma sin stock.
 * R13: una entrega tardía no reabre una línea cerrada.
 */
class PedidoDesfaseRecepcionTest extends TestCase
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

    public function test_r07_firma_valida_ingresa_q_y_cierra_con_desfase(): void
    {
        $creador = $this->usuario('contabilidad');
        [$pedido, $receptor, $linea, $novedad] = $this->pedidoConNovedadAbierta(creador: $creador);

        $firma = $this->autorizar($receptor, $pedido, $novedad->novedad_id)->assertOk();
        $autorizacionId = (int) $firma->json('data.autorizacion.autorizacion_id');

        // Firmar no mueve stock; la recepción combinada por UUID nuevo tampoco
        // habilita un segundo ingreso silencioso.
        $this->assertDatabaseCount('transaccion_inventarios', 1);

        $segunda = $this->abrirRecepcion($receptor, $pedido)->assertStatus(201);
        $recepcionId = (int) $segunda->json('data.recepcion_id');

        // El conteo firmado se reutiliza: no hace falta recontar la línea.
        $this->assertSame(
            1,
            PedidoRecepcionLinea::query()->where('recepcion_id', $recepcionId)->count(),
        );
        $this->assertNull(
            PedidoRecepcionLinea::query()
                ->where('recepcion_id', $recepcionId)
                ->where('pedido_linea_id', $linea->linea_id)
                ->value('conteo_total'),
        );

        $respuesta = $this->confirmar($receptor, $pedido, $recepcionId)->assertOk();

        $respuesta->assertJsonPath('data.resulting_state', PedidoEstado::RECIBIDO_CON_DESFASE)
            ->assertJsonPath('data.posted_lines.0.line_id', $linea->linea_id)
            ->assertJsonPath('data.posted_lines.0.quantity', '2.00')
            ->assertJsonPath('data.posted_lines.0.authorization_id', $autorizacionId)
            ->assertJsonPath('data.closed_without_stock', [])
            ->assertJsonPath('data.authorizations_invalidated', [])
            ->assertJsonPath('data.novedades_abiertas', 0);

        // Un ingreso por línea y total exacto: 4.00 conforme + 2.00 firmado.
        $transacciones = TransaccionInventario::query()->orderBy('transaccion_id')->get();
        $this->assertCount(2, $transacciones);

        $firmada = TransaccionInventario::query()->where('pedido_linea_id', $linea->linea_id)->sole();
        $this->assertSame('2.00', PedidoSnapshot::cantidadCanonica($firmada->transaccion_cantidad));
        $this->assertSame((int) $receptor->id, (int) $firmada->usuario_id);
        $this->assertSame((int) $pedido->bodega_id, (int) $firmada->bodega_id);
        $this->assertStringContainsString('desfase', (string) $firmada->transaccion_notas);

        $this->assertSame(LineaEstado::RECIBIDA_CON_DESFASE, $linea->fresh()->estado);
        $this->assertSame(PedidoEstado::RECIBIDO_CON_DESFASE, $pedido->fresh()->estado);
        $this->assertSame(NovedadEstado::CERRADA, $novedad->fresh()->estado);
        $this->assertNotNull($novedad->fresh()->cerrada_at);

        $autorizacion = PedidoAutorizacion::query()->findOrFail($autorizacionId);
        $this->assertSame(PedidoAutorizacion::ESTADO_CONSUMIDA, $autorizacion->estado);
        $this->assertSame($recepcionId, (int) $autorizacion->consumida_por_recepcion_id);
        $this->assertNotNull($autorizacion->consumida_at);

        // Evento y alerta de resumen con desfase SOLO a contabilidad.
        $this->assertSame(1, PedidoEvento::query()->where('event_type', 'recibido_con_desfase')->count());
        $alertas = PedidoAlerta::query()->where('tipo', 'recibido_con_desfase')->get();
        $this->assertCount(1, $alertas);
        $this->assertSame((int) $creador->id, (int) $alertas->first()->destinatario_user_id);
    }

    public function test_r10_cierre_sin_ingreso_consume_la_firma_con_cero_movimientos(): void
    {
        $this->configurarD1(cierreSinIngreso: true);

        [$pedido, $receptor, $linea, $novedad] = $this->pedidoConNovedadAbierta(contado: '0.00', aptos: '0.00');

        $firma = $this->autorizar($receptor, $pedido, $novedad->novedad_id)->assertOk();
        $autorizacionId = (int) $firma->json('data.autorizacion.autorizacion_id');

        $segunda = $this->abrirRecepcion($receptor, $pedido)->assertStatus(201);
        $recepcionId = (int) $segunda->json('data.recepcion_id');

        $respuesta = $this->confirmar($receptor, $pedido, $recepcionId)->assertOk();

        $respuesta->assertJsonPath('data.resulting_state', PedidoEstado::RECIBIDO_CON_DESFASE)
            ->assertJsonPath('data.posted_lines', [])
            ->assertJsonPath('data.closed_without_stock.0.line_id', $linea->linea_id)
            ->assertJsonPath('data.closed_without_stock.0.authorization_id', $autorizacionId)
            ->assertJsonPath('data.closed_without_stock.0.reason', 'cierre_sin_ingreso');

        // Cero movimientos nuevos: nunca una transacción de cantidad cero.
        $this->assertSame(1, TransaccionInventario::query()->count());
        $this->assertSame(LineaEstado::CIERRE_SIN_INGRESO, $linea->fresh()->estado);
        $this->assertSame(NovedadEstado::CERRADA, $novedad->fresh()->estado);

        $autorizacion = PedidoAutorizacion::query()->findOrFail($autorizacionId);
        $this->assertSame(PedidoAutorizacion::ESTADO_CONSUMIDA, $autorizacion->estado);
    }

    public function test_r12_un_conteo_nuevo_invalida_la_firma_y_no_ingresa(): void
    {
        [$pedido, $receptor, $linea, $novedad] = $this->pedidoConNovedadAbierta();

        $firma = $this->autorizar($receptor, $pedido, $novedad->novedad_id)->assertOk();
        $autorizacionId = (int) $firma->json('data.autorizacion.autorizacion_id');

        // Recuento posterior a la firma: el contexto firmado ya no rige.
        $segunda = $this->abrirRecepcion($receptor, $pedido)->assertStatus(201);
        $recepcionId = (int) $segunda->json('data.recepcion_id');

        $this->contar($receptor, $pedido, $recepcionId, $linea, [
            'conteo_total' => '2.00',
            'conteo_aptos' => '2.00',
            'estado_condicion' => 'conforme',
        ])->assertOk();

        $respuesta = $this->confirmar($receptor, $pedido, $recepcionId)->assertOk();

        // La firma vieja se invalida (contexto cambiado) y el recuento, al ser
        // un faltante puro, ingresa parcial con saldo abierto (U10): el
        // ingreso del split NO consume la firma invalidada.
        $respuesta->assertJsonPath('data.posted_lines.0.line_id', $linea->linea_id)
            ->assertJsonPath('data.posted_lines.0.quantity', '2.00')
            ->assertJsonPath('data.posted_lines.0.authorization_id', null)
            ->assertJsonPath('data.authorizations_invalidated.0.authorization_id', $autorizacionId)
            ->assertJsonPath('data.authorizations_invalidated.0.reason', 'contexto_cambiado')
            ->assertJsonPath('data.splits.0.line_id', $linea->linea_id)
            ->assertJsonPath('data.splits.0.saldo_restante', '2.00')
            ->assertJsonPath('data.blocked_lines', [])
            ->assertJsonPath('data.resulting_state', PedidoEstado::PARCIAL_CON_NOVEDAD);

        // Sin consumo: la firma quedó invalidada con auditoría y el saldo vive
        // en la hija.
        $this->assertSame(2, TransaccionInventario::query()->count());
        $this->assertSame(LineaEstado::RECIBIDA_PARCIAL, $linea->fresh()->estado);
        // La novedad original se resolvió por el split; el saldo vive en la
        // hija con novedad propia abierta.
        $this->assertSame(NovedadEstado::RESUELTA, $novedad->fresh()->estado);
        $this->assertSame(1, PedidoNovedad::query()
            ->where('pedido_linea_id', '!=', $linea->linea_id)
            ->where('estado', NovedadEstado::ABIERTA)
            ->count());

        $autorizacion = PedidoAutorizacion::query()->findOrFail($autorizacionId);
        $this->assertSame(PedidoAutorizacion::ESTADO_INVALIDADA, $autorizacion->estado);
        $this->assertNull($autorizacion->consumida_at);
        $this->assertSame(1, PedidoEvento::query()
            ->where('event_type', 'desfase_invalidado')
            ->where('pedido_linea_id', $linea->linea_id)
            ->count());
    }

    public function test_r12_recontar_el_mismo_valor_con_otro_motivo_invalida(): void
    {
        [$pedido, $receptor, $linea, $novedad] = $this->pedidoConNovedadAbierta();

        $firma = $this->autorizar($receptor, $pedido, $novedad->novedad_id)->assertOk();
        $autorizacionId = (int) $firma->json('data.autorizacion.autorizacion_id');

        $segunda = $this->abrirRecepcion($receptor, $pedido)->assertStatus(201);
        $recepcionId = (int) $segunda->json('data.recepcion_id');

        // Mismos valores, otro motivo de conteo: el contexto ya no coincide.
        $this->contar($receptor, $pedido, $recepcionId, $linea, [
            'conteo_total' => '3.00',
            'conteo_aptos' => '2.00',
            'estado_condicion' => 'conforme',
            'motivo' => 'Recuento posterior con observación distinta',
        ])->assertOk();

        $this->confirmar($receptor, $pedido, $recepcionId)
            ->assertOk()
            ->assertJsonPath('data.authorizations_invalidated.0.authorization_id', $autorizacionId);

        $this->assertSame(1, TransaccionInventario::query()->count());
        $this->assertSame(PedidoAutorizacion::ESTADO_INVALIDADA, PedidoAutorizacion::query()->findOrFail($autorizacionId)->estado);
    }

    public function test_r13_entrega_tardia_no_reabre_la_linea_cerrada_y_guia_pedido_complementario(): void
    {
        $sufijo = Str::upper(Str::random(6));
        $creador = $this->usuario('contabilidad');
        $receptor = $this->receptorOperativo();

        $productos = [
            $this->productoConSku("SKU-R13-A-{$sufijo}"),
            $this->productoConSku("SKU-R13-B-{$sufijo}"),
            $this->productoConSku("SKU-R13-C-{$sufijo}"),
        ];

        [$pedido, , $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => "SKU-R13-A-{$sufijo}", 'overrides' => ['producto_id' => $productos[0]->producto_id]],
            ['sku' => "SKU-R13-B-{$sufijo}", 'overrides' => ['producto_id' => $productos[1]->producto_id]],
            ['sku' => "SKU-R13-C-{$sufijo}", 'overrides' => ['producto_id' => $productos[2]->producto_id]],
        ], $creador, $receptor);

        $lineaOk = $this->lineaDe($pedido, 1);
        $lineaFirmada = $this->lineaDe($pedido, 2);
        $lineaAbierta = $this->lineaDe($pedido, 3);

        $this->contar($receptor, $pedido, (int) $recepcion['recepcion_id'], $lineaOk, [
            'conteo_total' => '4.00', 'conteo_aptos' => '4.00', 'estado_condicion' => 'conforme',
        ])->assertOk();
        $this->contar($receptor, $pedido, (int) $recepcion['recepcion_id'], $lineaFirmada, [
            'conteo_total' => '3.00', 'conteo_aptos' => '2.00', 'estado_condicion' => 'conforme',
        ])->assertOk();
        $this->contar($receptor, $pedido, (int) $recepcion['recepcion_id'], $lineaAbierta, [
            'conteo_total' => '3.00', 'conteo_aptos' => '2.00', 'estado_condicion' => 'conforme',
        ])->assertOk();

        $this->confirmar($receptor, $pedido, (int) $recepcion['recepcion_id'])->assertOk();

        $novedadFirmada = PedidoNovedad::query()
            ->where('pedido_linea_id', $lineaFirmada->linea_id)
            ->where('estado', 'abierta')
            ->sole();

        $this->autorizar($receptor, $pedido, $novedadFirmada->novedad_id)->assertOk();

        $segunda = $this->abrirRecepcion($receptor, $pedido)->assertStatus(201);
        $segundaId = (int) $segunda->json('data.recepcion_id');

        // La línea que sigue abierta se reconta en la sesión nueva; la firmada
        // se confirma con el conteo que abrió la novedad.
        $this->contar($receptor, $pedido, $segundaId, $lineaAbierta, [
            'conteo_total' => '3.00', 'conteo_aptos' => '2.00', 'estado_condicion' => 'conforme',
        ])->assertOk();

        $this->confirmar($receptor, $pedido, $segundaId)->assertOk();

        $this->assertSame(LineaEstado::RECIBIDA_CON_DESFASE, $lineaFirmada->fresh()->estado);
        $this->assertSame(PedidoEstado::PARCIAL_CON_NOVEDAD, $pedido->fresh()->estado);

        // Nueva sesión: la línea cerrada no se incluye ni se puede recontar.
        $tercera = $this->abrirRecepcion($receptor, $pedido)->assertStatus(201);
        $terceraId = (int) $tercera->json('data.recepcion_id');

        $this->assertSame(1, PedidoRecepcionLinea::query()->where('recepcion_id', $terceraId)->count());
        $this->assertSame(
            $lineaAbierta->linea_id,
            (int) PedidoRecepcionLinea::query()->where('recepcion_id', $terceraId)->value('pedido_linea_id'),
        );

        $rechazo = $this->contar($receptor, $pedido, $terceraId, $lineaFirmada, [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'conforme',
        ])->assertStatus(409);

        $rechazo->assertJsonPath('requiere_pedido_complementario', true)
            ->assertJsonPath('linea_terminal', true);

        // Ninguna entrega tardía agrega stock a la línea cerrada.
        $this->assertSame(1, TransaccionInventario::query()->where('pedido_linea_id', $lineaFirmada->linea_id)->count());
        $this->assertSame(2, TransaccionInventario::query()->count());
    }

    public function test_retiro_documental_invalida_firmas_vigentes_con_auditoria(): void
    {
        $creador = $this->usuario('contabilidad');
        $empleado = $this->empleado();

        $pedido = PedidoInventario::create([
            'bodega_id' => $this->bodega()->bodega_id,
            'created_by' => $creador->id,
            'proveedor_nombre_confirmado' => 'PROVEEDOR HISTÓRICO',
            'proveedor_nombre_raw' => 'PROVEEDOR HISTÓRICO',
        ]);
        $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION);

        $linea = PedidoInventarioLinea::create([
            'pedido_id' => $pedido->pedido_id,
            'orden' => 1,
            'sku_confirmado' => 'SKU-RETIRO-U7',
            'cantidad_esperada' => '4.00',
        ]);

        $novedad = PedidoNovedad::create([
            'pedido_linea_id' => $linea->linea_id,
            'tipo' => 'faltante',
            'motivo' => 'Novedad histórica previa al retiro',
        ]);

        $autorizacion = PedidoAutorizacion::create([
            'novedad_id' => $novedad->novedad_id,
            'actor_user_id' => $creador->id,
            'empleado_id' => $empleado->id,
            'rol_snapshot' => 'auxiliar_bodega',
            'cantidad_autorizada' => '3.00',
            'order_version' => $pedido->versionActual(),
            'line_version' => $linea->versionActual(),
            'count_revision' => 1,
            'contexto_hash' => str_repeat('a', 64),
            'diferencia_cantidad' => '-1.00',
            'motivo' => 'Firma histórica',
            'aceptacion_responsabilidad' => 'v1|texto',
            'aceptada_at' => now(),
        ]);

        Sanctum::actingAs($creador);

        $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/return-for-correction", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => $pedido->fresh()->versionActual(),
            'motivo' => 'Corrección documental antes del ingreso',
        ])->assertOk();

        $this->assertSame(PedidoAutorizacion::ESTADO_INVALIDADA, $autorizacion->fresh()->estado);

        $evento = PedidoEvento::query()
            ->where('event_type', 'desfase_invalidado')
            ->where('pedido_id', $pedido->pedido_id)
            ->sole();

        $this->assertSame('retiro_documental', $evento->datos_despues['motivo']);
        $this->assertSame((int) $autorizacion->autorizacion_id, (int) $evento->datos_despues['autorizacion_id']);
    }
}
