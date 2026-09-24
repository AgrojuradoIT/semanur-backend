<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\LineaEstado;
use App\Domain\InventoryOrders\NovedadEstado;
use App\Domain\InventoryOrders\PedidoEstado;
use App\Models\BodegaProducto;
use App\Models\PedidoAlerta;
use App\Models\PedidoAutorizacion;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoNovedad;
use App\Models\PedidoOperacion;
use App\Models\PedidoRecepcion;
use App\Models\PedidoRecepcionLinea;
use App\Models\TransaccionInventario;
use App\Models\User;
use App\Services\Inventory\Orders\PedidoRecepcionService;
use App\Services\Inventory\Orders\PedidoSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Feature\InventoryOrders\Concerns\PedidoDesfaseFixtures;
use Tests\TestCase;

/**
 * Saldo abierto / backorder (U10).
 *
 * Un faltante puro (C = A < E, condición conforme, mismo SKU) ingresa lo apto
 * contado SIN firma y deja el saldo D = E − A abierto en una hija vinculada.
 * R08 rige por línea-hija: un ingreso definitivo por línea. Daño, SKU
 * distinto, A = 0 y sobrantes conservan su camino existente (novedad completa,
 * cero split).
 */
class PedidoSaldoAbiertoTest extends TestCase
{
    use PedidoDesfaseFixtures, RefreshDatabase;

    private static bool $bloquearHijas = false;

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
        self::$bloquearHijas = false;
        $this->limpiarDocumentosOcr();

        parent::tearDown();
    }

    private function canon(mixed $valor): string
    {
        return (string) PedidoSnapshot::cantidadCanonica($valor);
    }

    /**
     * @return array{0: PedidoInventario, 1: User, 2: array<string, mixed>, 3: PedidoInventarioLinea}
     */
    private function pedidoConFaltantePuro(
        string $esperado = '4.00',
        string $contado = '3.00',
        ?User $creador = null,
    ): array {
        $creador ??= $this->usuario('contabilidad');
        $producto = $this->productoConSku('SKU-U10-'.Str::upper(Str::random(6)));

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            [
                'sku' => $producto->producto_sku,
                'cantidad' => $esperado,
                'overrides' => ['producto_id' => $producto->producto_id],
            ],
        ], $creador);

        $linea = $this->lineaDe($pedido, 1);

        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $linea, [
            'conteo_total' => $contado,
            'conteo_aptos' => $contado,
            'estado_condicion' => 'conforme',
        ])->assertOk();

        return [$pedido, $receptor, $recepcion, $linea];
    }

    public function test_faltante_puro_ingresa_lo_apto_y_abre_el_saldo_en_hija(): void
    {
        $creador = $this->usuario('contabilidad');
        [$pedido, $receptor, $recepcion, $linea] = $this->pedidoConFaltantePuro(creador: $creador);
        $productoId = (int) $linea->fresh()->producto_id;

        $respuesta = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $respuesta->assertJsonPath('data.resulting_state', PedidoEstado::PARCIAL_CON_NOVEDAD)
            ->assertJsonPath('data.posted_lines.0.line_id', $linea->linea_id)
            ->assertJsonPath('data.posted_lines.0.quantity', '3.00')
            ->assertJsonPath('data.blocked_lines', [])
            ->assertJsonPath('data.splits.0.line_id', $linea->linea_id)
            ->assertJsonPath('data.splits.0.parent_linea_id', $linea->linea_id)
            ->assertJsonPath('data.splits.0.quantity', '3.00')
            ->assertJsonPath('data.splits.0.saldo_restante', '1.00')
            ->assertJsonPath('data.novedades_abiertas', 1);

        $hijaId = (int) $respuesta->json('data.splits.0.hija_line_id');
        $hijaIssueId = (int) $respuesta->json('data.splits.0.hija_issue_id');
        $this->assertNotSame($linea->linea_id, $hijaId);

        // Un ingreso definitivo sobre la línea origen (R08 por línea-hija).
        $transaccion = TransaccionInventario::query()->where('pedido_linea_id', $linea->linea_id)->sole();
        $this->assertSame('3.00', $this->canon($transaccion->transaccion_cantidad));
        $this->assertSame($productoId, (int) $transaccion->producto_id);
        $this->assertSame(1, TransaccionInventario::query()->count());

        // Stock exacto: solo lo apto contado.
        $this->assertSame('3.00', $this->canon(
            \App\Models\Producto::query()->whereKey($productoId)->value('producto_stock_actual'),
        ));
        $this->assertSame('3.00', $this->canon(
            BodegaProducto::query()
                ->where('bodega_id', $pedido->bodega_id)
                ->where('producto_id', $productoId)
                ->value('cantidad'),
        ));

        // Origen terminal parcial; hija pendiente con el saldo y su novedad.
        $this->assertSame(LineaEstado::RECIBIDA_PARCIAL, $linea->fresh()->estado);

        $hija = PedidoInventarioLinea::query()->findOrFail($hijaId);
        $this->assertSame((int) $linea->linea_id, (int) $hija->parent_linea_id);
        $this->assertSame((int) $pedido->pedido_id, (int) $hija->pedido_id);
        $this->assertSame('1.00', $this->canon($hija->cantidad_esperada));
        $this->assertSame(LineaEstado::PENDIENTE, (string) $hija->estado);
        $this->assertSame($productoId, (int) $hija->producto_id);
        $this->assertSame((string) $linea->fresh()->sku_confirmado, (string) $hija->sku_confirmado);
        $this->assertSame((string) $linea->fresh()->unidad, (string) $hija->unidad);
        $this->assertSame(2, (int) $hija->orden);

        $novedadHija = PedidoNovedad::query()->findOrFail($hijaIssueId);
        $this->assertSame($hijaId, (int) $novedadHija->pedido_linea_id);
        $this->assertSame(NovedadEstado::ABIERTA, (string) $novedadHija->estado);
        $this->assertSame('faltante', (string) $novedadHija->tipo);
        $this->assertSame('1.00', $this->canon($novedadHija->esperado_snapshot));
        $this->assertSame('0.00', $this->canon($novedadHija->contado_snapshot));
        $this->assertSame('0.00', $this->canon($novedadHija->aptos_snapshot));
        $this->assertSame(1, PedidoNovedad::query()->count());

        $this->assertSame(PedidoEstado::PARCIAL_CON_NOVEDAD, $pedido->fresh()->estado);

        // Cobertura agrupada habitual con el vínculo de la hija en datos.
        $eventoNovedad = PedidoEvento::query()->where('event_type', 'novedad_abierta')->sole();
        $this->assertContains($hijaIssueId, $eventoNovedad->datos_despues['novedades']);
        $this->assertSame($hijaId, (int) $eventoNovedad->datos_despues['splits'][0]['hija_linea_id']);
        $this->assertSame('1.00', $eventoNovedad->datos_despues['splits'][0]['saldo_restante']);

        $alertaNovedad = PedidoAlerta::query()->where('tipo', 'novedad_abierta')->sole();
        $this->assertSame($eventoNovedad->event_uuid, (string) $alertaNovedad->event_uuid);
        $this->assertSame((int) $creador->id, (int) $alertaNovedad->destinatario_user_id);
        $this->assertSame(1, PedidoAlerta::query()->where('tipo', 'recibido_con_novedad')->count());

        // El detalle expone el linaje sin romper los campos existentes.
        Sanctum::actingAs($receptor);
        $detalle = $this->getJson("/api/web/inventory-orders/{$pedido->uuid}")->assertOk();
        $lineas = collect($detalle->json('data.lineas'))->keyBy('linea_id');
        $this->assertNull($lineas[(string) $linea->linea_id]['parent_linea_id']);
        $this->assertSame($linea->linea_id, (int) $lineas[(string) $hijaId]['parent_linea_id']);
        $this->assertSame('recibida_parcial', $lineas[(string) $linea->linea_id]['estado']);
    }

    public function test_segundo_conteo_ingresa_el_saldo_y_cierra_el_pedido(): void
    {
        [$pedido, $receptor, $recepcion, $linea] = $this->pedidoConFaltantePuro();

        $primera = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();
        $hijaId = (int) $primera->json('data.splits.0.hija_line_id');
        $hija = PedidoInventarioLinea::query()->findOrFail($hijaId);

        // La hija exige su propio conteo en la sesión nueva.
        $segunda = $this->abrirRecepcion($receptor, $pedido)->assertStatus(201);
        $segundaId = (int) $segunda->json('data.recepcion_id');

        $this->assertSame(1, PedidoRecepcionLinea::query()->where('recepcion_id', $segundaId)->count());
        $this->assertSame(
            $hijaId,
            (int) PedidoRecepcionLinea::query()->where('recepcion_id', $segundaId)->value('pedido_linea_id'),
        );

        $this->confirmar($receptor, $pedido, $segundaId)
            ->assertStatus(409)
            ->assertJsonPath('lineas_sin_contar', [$hijaId]);

        $this->contar($receptor, $pedido, $segundaId, $hija, [
            'conteo_total' => '1.00',
            'conteo_aptos' => '1.00',
            'estado_condicion' => 'conforme',
        ])->assertOk();

        $respuesta = $this->confirmar($receptor, $pedido, $segundaId)->assertOk();

        $respuesta->assertJsonPath('data.resulting_state', PedidoEstado::RECIBIDO)
            ->assertJsonPath('data.posted_lines.0.line_id', $hijaId)
            ->assertJsonPath('data.posted_lines.0.quantity', '1.00')
            ->assertJsonPath('data.splits', [])
            ->assertJsonPath('data.novedades_abiertas', 0);

        // Totales exactos: 3.00 + 1.00 = 4.00 esperadas, sin reingreso del padre.
        $this->assertSame(1, TransaccionInventario::query()->where('pedido_linea_id', $linea->linea_id)->count());
        $this->assertSame('3.00', $this->canon(
            TransaccionInventario::query()->where('pedido_linea_id', $linea->linea_id)->value('transaccion_cantidad'),
        ));
        $this->assertSame('1.00', $this->canon(
            TransaccionInventario::query()->where('pedido_linea_id', $hijaId)->value('transaccion_cantidad'),
        ));
        $this->assertSame(2, TransaccionInventario::query()->count());

        $productoId = (int) $linea->fresh()->producto_id;
        $this->assertSame('4.00', $this->canon(
            \App\Models\Producto::query()->whereKey($productoId)->value('producto_stock_actual'),
        ));
        $this->assertSame('4.00', $this->canon(
            BodegaProducto::query()
                ->where('bodega_id', $pedido->bodega_id)
                ->where('producto_id', $productoId)
                ->value('cantidad'),
        ));

        $this->assertSame(LineaEstado::RECIBIDA, $hija->fresh()->estado);
        $this->assertSame(LineaEstado::RECIBIDA_PARCIAL, $linea->fresh()->estado);
        $this->assertSame(PedidoEstado::RECIBIDO, $pedido->fresh()->estado);
        $this->assertSame(
            NovedadEstado::RESUELTA,
            (string) PedidoNovedad::query()->where('pedido_linea_id', $hijaId)->value('estado'),
        );
    }

    public function test_hija_puede_dividirse_de_nuevo_en_otro_ingreso_parcial(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoConFaltantePuro('4.00', '3.00');

        $primera = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();
        $hija = PedidoInventarioLinea::query()->findOrFail((int) $primera->json('data.splits.0.hija_line_id'));

        // La hija (E=1.00) se cuenta parcial (A=0.25): segundo split.
        $segunda = $this->abrirRecepcion($receptor, $pedido)->assertStatus(201);
        $segundaId = (int) $segunda->json('data.recepcion_id');

        $this->contar($receptor, $pedido, $segundaId, $hija, [
            'conteo_total' => '0.25',
            'conteo_aptos' => '0.25',
            'estado_condicion' => 'conforme',
        ])->assertOk();

        $respuesta = $this->confirmar($receptor, $pedido, $segundaId)->assertOk();
        $nietaId = (int) $respuesta->json('data.splits.0.hija_line_id');

        $respuesta->assertJsonPath('data.resulting_state', PedidoEstado::PARCIAL_CON_NOVEDAD)
            ->assertJsonPath('data.posted_lines.0.line_id', $hija->linea_id)
            ->assertJsonPath('data.posted_lines.0.quantity', '0.25')
            ->assertJsonPath('data.splits.0.saldo_restante', '0.75');

        $nieta = PedidoInventarioLinea::query()->findOrFail($nietaId);
        $this->assertSame((int) $hija->linea_id, (int) $nieta->parent_linea_id);
        $this->assertSame('0.75', $this->canon($nieta->cantidad_esperada));
        $this->assertSame(LineaEstado::RECIBIDA_PARCIAL, $hija->fresh()->estado);
        $this->assertSame(1, TransaccionInventario::query()->where('pedido_linea_id', $hija->linea_id)->count());

        // Stock acumulado exacto: 3.00 + 0.25.
        $productoId = (int) $hija->fresh()->producto_id;
        $this->assertSame('3.25', $this->canon(
            \App\Models\Producto::query()->whereKey($productoId)->value('producto_stock_actual'),
        ));
    }

    public function test_a_cero_queda_abierto_sin_split_ni_ingreso(): void
    {
        [$pedido, $receptor, $recepcion, $linea] = $this->pedidoConFaltantePuro('4.00', '0.00');

        $respuesta = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $respuesta->assertJsonPath('data.posted_lines', [])
            ->assertJsonPath('data.splits', [])
            ->assertJsonPath('data.blocked_lines.0.line_id', $linea->linea_id)
            ->assertJsonPath('data.blocked_lines.0.reason', 'faltante');

        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertSame(1, PedidoInventarioLinea::query()->where('pedido_id', $pedido->pedido_id)->count());
        $this->assertSame(LineaEstado::NOVEDAD, $linea->fresh()->estado);
        $this->assertSame(PedidoEstado::PARCIAL_CON_NOVEDAD, $pedido->fresh()->estado);
    }

    public function test_dano_y_sku_incorrecto_son_novedad_completa_sin_split(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-U10-DAN'],
            ['sku' => 'SKU-U10-SKU'],
        ]);

        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $this->lineaDe($pedido, 1), [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'danado',
            'motivo' => 'Empaque roto en todas las unidades',
        ])->assertOk();

        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $this->lineaDe($pedido, 2), [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'sku_incorrecto',
            'motivo' => 'Llegó otra referencia',
        ])->assertOk();

        $respuesta = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $respuesta->assertJsonPath('data.posted_lines', [])
            ->assertJsonPath('data.splits', [])
            ->assertJsonPath('data.blocked_lines.0.reason', 'danado')
            ->assertJsonPath('data.blocked_lines.1.reason', 'sku_incorrecto');

        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertSame(2, PedidoInventarioLinea::query()->where('pedido_id', $pedido->pedido_id)->count());
        $this->assertSame(2, PedidoNovedad::query()->where('estado', 'abierta')->count());
    }

    public function test_replay_y_segunda_confirmacion_no_reingresan_al_padre(): void
    {
        [$pedido, $receptor, $recepcion, $linea] = $this->pedidoConFaltantePuro();

        $operacion = (string) Str::uuid();

        $primera = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'], [
            'client_operation_id' => $operacion,
            'version' => 1,
        ])->assertOk();

        // Replay exacto: el mismo desglose con hijas, sin filas nuevas.
        $replay = $this->confirmar($receptor, $pedido->fresh(), $recepcion['recepcion_id'], [
            'client_operation_id' => $operacion,
            'version' => 1,
        ])->assertOk();

        $replay->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('data.splits.0.hija_line_id', $primera->json('data.splits.0.hija_line_id'))
            ->assertJsonPath('data.splits.0.saldo_restante', '1.00');

        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame(2, PedidoInventarioLinea::query()->where('pedido_id', $pedido->pedido_id)->count());

        // UUID nuevo sobre la recepción ya confirmada: 409, un solo movimiento.
        $this->confirmar($receptor, $pedido->fresh(), $recepcion['recepcion_id'])->assertStatus(409);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame(2, PedidoInventarioLinea::query()->where('pedido_id', $pedido->pedido_id)->count());
        $this->assertSame(LineaEstado::RECIBIDA_PARCIAL, $linea->fresh()->estado);
    }

    public function test_rollback_total_ante_fallo_durante_el_split(): void
    {
        [$pedido, $receptor, $recepcion, $linea] = $this->pedidoConFaltantePuro();

        // Fallo inducido en la creación de la hija: el movimiento ya se
        // posteó y proyectó stock dentro de la misma transacción.
        PedidoInventarioLinea::creating(function (PedidoInventarioLinea $nueva): void {
            if (self::$bloquearHijas && $nueva->parent_linea_id !== null) {
                throw new RuntimeException('Fallo inducido al abrir la hija.');
            }
        });
        self::$bloquearHijas = true;

        $servicio = $this->app->make(PedidoRecepcionService::class);

        try {
            $servicio->confirmar(
                $pedido->fresh(),
                PedidoRecepcion::query()->findOrFail($recepcion['recepcion_id']),
                $receptor,
                1,
                (string) Str::uuid(),
            );

            $this->fail('La confirmación debía fallar.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo inducido al abrir la hija.', $exception->getMessage());
        } finally {
            self::$bloquearHijas = false;
        }

        // NADA sobrevive: ni ledger, ni hija, ni novedades, ni eventos.
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertSame(1, PedidoInventarioLinea::query()->where('pedido_id', $pedido->pedido_id)->count());
        $this->assertDatabaseCount('pedido_novedades', 0);
        $this->assertSame(0, PedidoEvento::query()->whereIn('event_type', ['novedad_abierta', 'recibido_con_novedad'])->count());
        $this->assertSame(0, PedidoAlerta::query()->whereIn('tipo', ['novedad_abierta', 'recibido_con_novedad'])->count());
        $this->assertSame(0, PedidoOperacion::query()->where('command', 'confirmar_recepcion')->count());

        $this->assertSame('borrador', PedidoRecepcion::query()->findOrFail($recepcion['recepcion_id'])->estado);
        $this->assertSame('en_validacion', $pedido->fresh()->estado);
        $this->assertSame('pendiente', $linea->fresh()->estado);
        $this->assertSame('0.00', $this->canon(
            \App\Models\Producto::query()->whereKey($linea->fresh()->producto_id)->value('producto_stock_actual'),
        ));
    }

    public function test_autorizar_y_cerrar_el_saldo_de_una_hija(): void
    {
        $this->configurarD1(cierreSinIngreso: true);

        $creador = $this->usuario('contabilidad');
        [$pedido, $receptor, $recepcion] = $this->pedidoConFaltantePuro(creador: $creador);

        $primera = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();
        $hija = PedidoInventarioLinea::query()->findOrFail((int) $primera->json('data.splits.0.hija_line_id'));
        $novedadHija = PedidoNovedad::query()
            ->where('pedido_linea_id', $hija->linea_id)
            ->where('estado', 'abierta')
            ->sole();

        // La hija (E=1.00) no trae nada: conteo 0/0 y firma de cierre.
        $segunda = $this->abrirRecepcion($receptor, $pedido)->assertStatus(201);
        $segundaId = (int) $segunda->json('data.recepcion_id');

        $this->contar($receptor, $pedido, $segundaId, $hija, [
            'conteo_total' => '0.00',
            'conteo_aptos' => '0.00',
            'estado_condicion' => 'conforme',
        ])->assertOk();

        $this->confirmar($receptor, $pedido, $segundaId)->assertOk();
        $this->assertSame(LineaEstado::NOVEDAD, $hija->fresh()->estado);

        $firma = $this->autorizar($receptor, $pedido, $novedadHija->fresh()->novedad_id)->assertOk();
        $autorizacionId = (int) $firma->json('data.autorizacion.autorizacion_id');
        $this->assertSame('0.00', $firma->json('data.autorizacion.cantidad_autorizada'));

        $tercera = $this->abrirRecepcion($receptor, $pedido)->assertStatus(201);
        $respuesta = $this->confirmar($receptor, $pedido, (int) $tercera->json('data.recepcion_id'))->assertOk();

        $respuesta->assertJsonPath('data.resulting_state', PedidoEstado::RECIBIDO_CON_DESFASE)
            ->assertJsonPath('data.posted_lines', [])
            ->assertJsonPath('data.closed_without_stock.0.line_id', $hija->linea_id)
            ->assertJsonPath('data.closed_without_stock.0.authorization_id', $autorizacionId);

        // Cero movimientos nuevos: el único ledger sigue siendo el parcial 3.00.
        $this->assertSame(1, TransaccionInventario::query()->count());
        $this->assertSame(LineaEstado::CIERRE_SIN_INGRESO, $hija->fresh()->estado);
        $this->assertSame(NovedadEstado::CERRADA, (string) $novedadHija->fresh()->estado);
        $this->assertSame(PedidoEstado::RECIBIDO_CON_DESFASE, $pedido->fresh()->estado);
        $this->assertSame(PedidoAutorizacion::ESTADO_CONSUMIDA, PedidoAutorizacion::query()->findOrFail($autorizacionId)->estado);
    }
}
