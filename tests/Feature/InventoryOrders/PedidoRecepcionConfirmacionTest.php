<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\NovedadEstado;
use App\Events\NotificationSent;
use App\Models\BodegaProducto;
use App\Models\PedidoAlerta;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoNovedad;
use App\Models\PedidoOperacion;
use App\Models\PedidoRecepcion;
use App\Models\PedidoRecepcionLinea;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use App\Models\User;
use App\Services\Inventory\Orders\PedidoRecepcionService;
use App\Services\Inventory\Orders\PedidoSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Tests\Feature\InventoryOrders\Concerns\PedidoRecepcionFixtures;
use Tests\TestCase;

/**
 * Confirmación atómica de recepción (U6; R01-R10/R12/R17 y C01-C04).
 *
 * Cada caso verifica estado, cantidades global/bodega, número de movimientos y
 * auditoría/alertas: un 200 por sí solo no demuestra éxito.
 */
class PedidoRecepcionConfirmacionTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $extra
     */
    private function contarConforme(User $actor, PedidoInventario $pedido, int $recepcionId, int $lineaId, string $cantidad = '4.00', array $extra = []): void
    {
        $this->contar($actor, $pedido, $recepcionId, \App\Models\PedidoInventarioLinea::query()->findOrFail($lineaId), array_merge([
            'conteo_total' => $cantidad,
            'conteo_aptos' => $cantidad,
            'estado_condicion' => 'conforme',
        ], $extra))->assertOk();
    }

    private function canon(mixed $valor): string
    {
        return (string) PedidoSnapshot::cantidadCanonica($valor);
    }

    public function test_r01_todas_conformes_ingresan_una_vez_y_pedido_recibido(): void
    {
        Event::fake([NotificationSent::class]);

        $creador = $this->usuario('contabilidad');
        $producto = $this->productoConSku('SKU-R01');

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-R01', 'overrides' => ['producto_id' => $producto->producto_id]],
        ], $creador);

        $linea = $this->lineaDe($pedido, 1);

        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $linea->linea_id);

        $respuesta = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $respuesta->assertJsonPath('idempotent_replay', false)
            ->assertJsonPath('data.resulting_state', 'recibido')
            ->assertJsonPath('data.posted_lines.0.line_id', $linea->linea_id)
            ->assertJsonPath('data.posted_lines.0.quantity', '4.00')
            ->assertJsonPath('data.blocked_lines', [])
            ->assertJsonPath('data.closed_without_stock', []);

        $transaccion = TransaccionInventario::query()->where('pedido_linea_id', $linea->linea_id)->sole();
        $this->assertSame('ingreso', $transaccion->transaccion_tipo);
        $this->assertSame('4.00', $this->canon($transaccion->transaccion_cantidad));
        $this->assertSame((int) $receptor->id, (int) $transaccion->usuario_id);
        $this->assertSame((int) $pedido->bodega_id, (int) $transaccion->bodega_id);

        $this->assertSame('4.00', $this->canon($producto->fresh()->producto_stock_actual));
        $this->assertSame('4.00', $this->canon(
            BodegaProducto::query()
                ->where('bodega_id', $pedido->bodega_id)
                ->where('producto_id', $producto->producto_id)
                ->value('cantidad'),
        ));

        $this->assertSame('recibido', $pedido->fresh()->estado);
        $this->assertSame('recibida', $linea->fresh()->estado);

        $recepcionFresca = PedidoRecepcion::query()->findOrFail($recepcion['recepcion_id']);
        $this->assertSame('confirmada', $recepcionFresca->estado);
        $this->assertSame(2, $recepcionFresca->versionActual());
        $this->assertNotNull($recepcionFresca->clave_operacion);
        $this->assertNotNull($recepcionFresca->confirmada_at);

        $observacion = PedidoRecepcionLinea::query()
            ->where('recepcion_linea_id', (int) $recepcion['lineas'][0]['observacion']['recepcion_linea_id'])
            ->sole();
        $this->assertTrue((bool) $observacion->count_confirmed);

        $this->assertSame(1, PedidoOperacion::query()->where('command', 'confirmar_recepcion')->count());
        $this->assertSame(1, PedidoEvento::query()->where('event_type', 'pedido_recibido')->count());
        $this->assertSame('pendiente', PedidoEvento::query()->where('event_type', 'pedido_recibido')->value('estado_despacho'));

        // Alerta de resumen solo a contabilidad (no al receptor de bodega).
        $alertas = PedidoAlerta::query()->where('tipo', 'pedido_recibido')->get();
        $this->assertCount(1, $alertas);
        $this->assertSame((int) $creador->id, (int) $alertas->first()->destinatario_user_id);
        $this->assertFalse(PedidoAlerta::query()
            ->where('tipo', 'pedido_recibido')
            ->where('destinatario_user_id', $receptor->id)
            ->exists());

        $this->assertDatabaseCount('notificaciones', 0);
        Event::assertNotDispatched(NotificationSent::class);
    }

    public function test_r02_primera_confirmacion_exige_todas_las_lineas_contadas(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-R02-1'],
            ['sku' => 'SKU-R02-2'],
        ]);

        $lineaUno = $this->lineaDe($pedido, 1);
        $lineaDos = $this->lineaDe($pedido, 2);

        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $lineaUno->linea_id);

        $respuesta = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertStatus(409);

        $respuesta->assertJsonPath('lineas_sin_contar', [$lineaDos->linea_id]);

        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertSame(0, PedidoOperacion::query()->where('command', 'confirmar_recepcion')->count());
        $this->assertSame(0, PedidoEvento::query()->whereIn('event_type', ['novedad_abierta', 'pedido_recibido', 'recibido_con_novedad'])->count());
        $this->assertSame('en_validacion', $pedido->fresh()->estado);
        $this->assertSame('borrador', PedidoRecepcion::query()->findOrFail($recepcion['recepcion_id'])->estado);
    }

    public function test_r02_cero_explicito_es_faltante_y_no_omitido(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-R02-1'],
        ]);

        $linea = $this->lineaDe($pedido, 1);

        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $linea, [
            'conteo_total' => '0.00',
            'conteo_aptos' => '0.00',
            'estado_condicion' => 'conforme',
        ])->assertOk();

        $respuesta = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $respuesta->assertJsonPath('data.resulting_state', 'parcial_con_novedad')
            ->assertJsonPath('data.posted_lines', [])
            ->assertJsonPath('data.blocked_lines.0.line_id', $linea->linea_id)
            ->assertJsonPath('data.blocked_lines.0.reason', 'faltante');

        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertSame('novedad', $linea->fresh()->estado);

        $novedad = PedidoNovedad::query()->where('pedido_linea_id', $linea->linea_id)->sole();
        $this->assertSame('abierta', $novedad->estado);
        $this->assertSame('faltante', $novedad->tipo);
        $this->assertSame('0.00', $this->canon($novedad->contado_snapshot));
        $this->assertSame('4.00', $this->canon($novedad->esperado_snapshot));
    }

    public function test_r03_mixta_ingresa_la_conforme_y_bloquea_la_novedad(): void
    {
        $creador = $this->usuario('contabilidad');
        $productoConforme = $this->productoConSku('SKU-R03-OK');

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-R03-OK', 'overrides' => ['producto_id' => $productoConforme->producto_id]],
            ['sku' => 'SKU-R03-FALTA'],
        ], $creador);

        $lineaOk = $this->lineaDe($pedido, 1);
        $lineaFalta = $this->lineaDe($pedido, 2);

        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $lineaOk->linea_id);
        // Aptos insuficientes (C=3/A=2): novedad completa sin ingreso. Un
        // faltante puro (C=A<E) ingresaría parcial con saldo abierto (U10).
        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $lineaFalta, [
            'conteo_total' => '3.00',
            'conteo_aptos' => '2.00',
            'estado_condicion' => 'conforme',
        ])->assertOk();

        $respuesta = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $respuesta->assertJsonPath('data.resulting_state', 'parcial_con_novedad')
            ->assertJsonPath('data.posted_lines.0.line_id', $lineaOk->linea_id)
            ->assertJsonPath('data.blocked_lines.0.line_id', $lineaFalta->linea_id)
            ->assertJsonPath('data.blocked_lines.0.reason', 'faltante');

        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame('4.00', $this->canon($productoConforme->fresh()->producto_stock_actual));
        $this->assertDatabaseCount('productos', 1); // la línea faltante no crea producto

        $this->assertSame('recibida', $lineaOk->fresh()->estado);
        $this->assertSame('novedad', $lineaFalta->fresh()->estado);
        $this->assertSame('parcial_con_novedad', $pedido->fresh()->estado);

        $this->assertSame(1, PedidoAlerta::query()->where('tipo', 'novedad_abierta')->count());
        $this->assertSame(
            (int) $creador->id,
            (int) PedidoAlerta::query()->where('tipo', 'novedad_abierta')->value('destinatario_user_id'),
        );
        $this->assertSame(1, PedidoAlerta::query()->where('tipo', 'recibido_con_novedad')->count());
        $this->assertFalse(PedidoAlerta::query()
            ->where('tipo', 'recibido_con_novedad')
            ->where('destinatario_user_id', $receptor->id)
            ->exists());
        $this->assertDatabaseCount('notificaciones', 0);
    }

    public function test_r04_todas_en_novedad_cero_movimientos_y_alertas_correctas(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-R04-1'],
            ['sku' => 'SKU-R04-2'],
        ]);

        foreach ([1, 2] as $orden) {
            // Ausencia explícita (A=0): novedad completa sin ingreso ni split.
            $this->contar(
                $receptor,
                $pedido,
                $recepcion['recepcion_id'],
                $this->lineaDe($pedido, $orden),
                [
                    'conteo_total' => '0.00',
                    'conteo_aptos' => '0.00',
                    'estado_condicion' => 'conforme',
                ],
            )->assertOk();
        }

        $respuesta = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $respuesta->assertJsonPath('data.resulting_state', 'parcial_con_novedad')
            ->assertJsonPath('data.posted_lines', [])
            ->assertJsonPath('data.novedades_abiertas', 2);

        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('bodega_producto', 0);
        $this->assertSame('parcial_con_novedad', $pedido->fresh()->estado);
        $this->assertSame(2, PedidoNovedad::query()->where('estado', 'abierta')->count());
        $this->assertSame(1, PedidoAlerta::query()->where('tipo', 'novedad_abierta')->count());
        $this->assertSame(1, PedidoAlerta::query()->where('tipo', 'recibido_con_novedad')->count());
    }

    public function test_r05_r06_reconteo_reemplaza_la_observacion_y_un_solo_ingreso(): void
    {
        $producto = $this->productoConSku('SKU-R05');

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-R05', 'overrides' => ['producto_id' => $producto->producto_id]],
        ]);

        $linea = $this->lineaDe($pedido, 1);
        $recepcionId = $recepcion['recepcion_id'];

        $this->contarConforme($receptor, $pedido, $recepcionId, $linea->linea_id, '3.00');
        $this->contarConforme($receptor, $pedido, $recepcionId, $linea->linea_id, '3.00', ['observacion_version' => 2]);
        $this->contarConforme($receptor, $pedido, $recepcionId, $linea->linea_id, '4.00', ['observacion_version' => 3]);

        $this->confirmar($receptor, $pedido, $recepcionId)->assertOk();

        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame('4.00', $this->canon($producto->fresh()->producto_stock_actual));

        $observacion = PedidoRecepcionLinea::query()->where('pedido_linea_id', $linea->linea_id)->sole();
        $this->assertSame('4.00', $this->canon($observacion->conteo_total));
        $this->assertCount(2, $observacion->snapshots['historial']);
        $this->assertSame('3.00', $observacion->snapshots['historial'][0]['conteo_total']);
        $this->assertSame('3.00', $observacion->snapshots['historial'][1]['conteo_total']);
    }

    public function test_r08_segunda_confirmacion_solo_procesa_lineas_no_terminales(): void
    {
        $productoOk = $this->productoConSku('SKU-R08-OK');
        $productoBloq = $this->productoConSku('SKU-R08-BLOQ');

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-R08-OK', 'overrides' => ['producto_id' => $productoOk->producto_id]],
            ['sku' => 'SKU-R08-BLOQ', 'overrides' => ['producto_id' => $productoBloq->producto_id]],
        ]);

        $lineaOk = $this->lineaDe($pedido, 1);
        $lineaBloqueada = $this->lineaDe($pedido, 2);

        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $lineaOk->linea_id);
        // Aptos insuficientes (C=3/A=2): novedad resoluble por reconteo. Un
        // faltante puro (C=A<E) abriría saldo en hija (U10), no novedad.
        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $lineaBloqueada, [
            'conteo_total' => '3.00',
            'conteo_aptos' => '2.00',
            'estado_condicion' => 'conforme',
        ])->assertOk();

        $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame('parcial_con_novedad', $pedido->fresh()->estado);

        // La ficha de una línea ya ingresada queda congelada (409, no 403: el
        // pedido sigue parcial y la capacidad existe).
        $this->patchJson(
            "/api/web/inventory-orders/{$pedido->uuid}/lines/{$lineaOk->linea_id}/catalog-draft",
            ['version' => $lineaOk->fresh()->versionActual(), 'unidad' => 'caja'],
        )->assertStatus(409);

        // Segunda sesión de recepción: solo la línea no terminal tiene observación.
        $segunda = $this->abrirRecepcion($receptor, $pedido)->assertStatus(201);
        $segundaRecepcionId = (int) $segunda->json('data.recepcion_id');
        $this->assertNotSame((int) $recepcion['recepcion_id'], $segundaRecepcionId);
        $this->assertSame(1, PedidoRecepcionLinea::query()->where('recepcion_id', $segundaRecepcionId)->count());
        $this->assertSame(
            $lineaBloqueada->linea_id,
            (int) PedidoRecepcionLinea::query()->where('recepcion_id', $segundaRecepcionId)->value('pedido_linea_id'),
        );

        // Llega la unidad faltante: reconteo conforme y confirmación. Antes de
        // recontar, la línea bloqueada sí exige un conteo nuevo en esta sesión
        // (las ya ingresadas, en cambio, no se recuentan).
        $this->confirmar($receptor, $pedido, $segundaRecepcionId)
            ->assertStatus(409)
            ->assertJsonPath('lineas_sin_contar', [$lineaBloqueada->linea_id]);

        $this->contarConforme($receptor, $pedido, $segundaRecepcionId, $lineaBloqueada->linea_id);

        $respuesta = $this->confirmar($receptor, $pedido, $segundaRecepcionId)->assertOk();

        $respuesta->assertJsonPath('data.resulting_state', 'recibido')
            ->assertJsonPath('data.posted_lines.0.line_id', $lineaBloqueada->linea_id);

        // La línea ingresada no se repite ni se recuenta (R17).
        $this->assertSame(1, TransaccionInventario::query()->where('pedido_linea_id', $lineaOk->linea_id)->count());
        $this->assertSame(1, TransaccionInventario::query()->where('pedido_linea_id', $lineaBloqueada->linea_id)->count());
        $this->assertSame(2, TransaccionInventario::query()->count());
        $this->assertSame('recibido', $pedido->fresh()->estado);

        $novedad = PedidoNovedad::query()->where('pedido_linea_id', $lineaBloqueada->linea_id)->sole();
        $this->assertSame(NovedadEstado::RESUELTA, $novedad->estado);
        $this->assertNotNull($novedad->cerrada_at);
    }

    public function test_replay_devuelve_el_resultado_original_y_un_uuid_nuevo_no_reingresa(): void
    {
        $producto = $this->productoConSku('SKU-REPLAY');

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-REPLAY', 'overrides' => ['producto_id' => $producto->producto_id]],
        ]);

        $linea = $this->lineaDe($pedido, 1);
        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $linea->linea_id);

        $operacion = (string) Str::uuid();

        $primera = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'], [
            'client_operation_id' => $operacion,
            'version' => 1,
        ])->assertOk();

        // El cliente conserva el UUID y el payload originales tras el timeout.
        $replay = $this->confirmar($receptor, $pedido->fresh(), $recepcion['recepcion_id'], [
            'client_operation_id' => $operacion,
            'version' => 1,
        ])->assertOk();

        $replay->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('data.resulting_state', $primera->json('data.resulting_state'))
            ->assertJsonPath('data.posted_lines.0.transaction_id', $primera->json('data.posted_lines.0.transaction_id'));

        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame(1, PedidoOperacion::query()->where('command', 'confirmar_recepcion')->count());

        // Mismo UUID con payload distinto (otra versión): 409 sin mutaciones.
        $this->confirmar($receptor, $pedido->fresh(), $recepcion['recepcion_id'], [
            'client_operation_id' => $operacion,
            'version' => 2,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'client_operation_id ya fue utilizado con un contenido diferente.');

        // UUID nuevo sobre la recepción ya confirmada: 409, un solo movimiento.
        $this->confirmar($receptor, $pedido->fresh(), $recepcion['recepcion_id'])->assertStatus(409);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame('recibido', $pedido->fresh()->estado);
    }

    public function test_r09_danado_o_sku_incorrecto_son_novedad_aunque_los_conteos_cuadren(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-R09-1'],
            ['sku' => 'SKU-R09-2'],
        ]);

        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $this->lineaDe($pedido, 1), [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'danado',
            'motivo' => 'Empaque roto',
        ])->assertOk();

        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $this->lineaDe($pedido, 2), [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'sku_incorrecto',
            'motivo' => 'Llegó otra referencia',
        ])->assertOk();

        $respuesta = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $respuesta->assertJsonPath('data.posted_lines', [])
            ->assertJsonPath('data.blocked_lines.0.reason', 'danado')
            ->assertJsonPath('data.blocked_lines.1.reason', 'sku_incorrecto');

        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertSame(2, PedidoNovedad::query()->count());
    }

    public function test_rollback_total_ante_fallo_tras_el_primer_ingreso(): void
    {
        $productoExistente = $this->productoConSku('SKU-RB-EXISTE');
        $categoriaId = $this->categoriaId();

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-RB-EXISTE', 'overrides' => ['producto_id' => $productoExistente->producto_id]],
            [
                'sku' => 'SKU-RB-NUEVO',
                'overrides' => [
                    'categoria_propuesta_id' => $categoriaId,
                    'unidad' => 'caja',
                ],
            ],
        ]);

        $lineaUno = $this->lineaDe($pedido, 1);
        $lineaDos = $this->lineaDe($pedido, 2);

        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $lineaUno->linea_id);
        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $lineaDos->linea_id);

        // Fallo inducido exactamente al crear el segundo movimiento: el primero
        // ya proyectó stock, creó producto nuevo, observación y estados.
        TransaccionInventario::creating(function (TransaccionInventario $transaccion) use ($lineaDos): void {
            if ((int) $transaccion->pedido_linea_id === (int) $lineaDos->linea_id) {
                throw new RuntimeException('Fallo inducido tras el primer ingreso.');
            }
        });

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
            $this->assertSame('Fallo inducido tras el primer ingreso.', $exception->getMessage());
        }

        // NADA sobrevive: stock, productos, novedades, eventos, alertas,
        // operaciones, recepción y estados vuelven a su punto de partida.
        $this->assertSame('0.00', $this->canon($productoExistente->fresh()->producto_stock_actual));
        $this->assertDatabaseCount('productos', 1);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('bodega_producto', 0);
        $this->assertDatabaseCount('pedido_novedades', 0);
        $this->assertSame(0, PedidoEvento::query()->whereIn('event_type', ['novedad_abierta', 'pedido_recibido', 'recibido_con_novedad'])->count());
        $this->assertSame(0, PedidoAlerta::query()->whereIn('tipo', ['novedad_abierta', 'pedido_recibido', 'recibido_con_novedad'])->count());
        $this->assertSame(0, PedidoOperacion::query()->where('command', 'confirmar_recepcion')->count());

        $this->assertSame('borrador', PedidoRecepcion::query()->findOrFail($recepcion['recepcion_id'])->estado);
        $this->assertSame('en_validacion', $pedido->fresh()->estado);
        $this->assertSame('pendiente', $lineaUno->fresh()->estado);
        $this->assertSame('pendiente', $lineaDos->fresh()->estado);

        $this->assertFalse((bool) PedidoRecepcionLinea::query()
            ->where('pedido_linea_id', $lineaUno->linea_id)
            ->value('count_confirmed'));
    }

    public function test_linea_con_ingreso_previo_y_estado_no_terminal_aborta_sin_cambios(): void
    {
        $productoUno = $this->productoConSku('SKU-PREV-1');
        $productoDos = $this->productoConSku('SKU-PREV-2');

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-PREV-1', 'overrides' => ['producto_id' => $productoUno->producto_id]],
            ['sku' => 'SKU-PREV-2', 'overrides' => ['producto_id' => $productoDos->producto_id]],
        ]);

        $lineaDos = $this->lineaDe($pedido, 2);

        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $this->lineaDe($pedido, 1)->linea_id);
        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $lineaDos->linea_id);

        // Movimiento pre-existente (sin cerrar la línea): simula inconsistencia
        // de datos o carrera perdida.
        DB::transaction(function () use ($lineaDos, $productoDos, $pedido, $receptor): void {
            TransaccionInventario::query()->forceCreate([
                'producto_id' => $productoDos->producto_id,
                'bodega_id' => $pedido->bodega_id,
                'usuario_id' => $receptor->id,
                'transaccion_tipo' => 'ingreso',
                'transaccion_cantidad' => '4.00',
                'transaccion_motivo' => 'Movimiento pre-existente de prueba',
                'pedido_linea_id' => $lineaDos->linea_id,
            ]);
        });

        $this->assertSame('4.00', $this->canon($productoDos->fresh()->producto_stock_actual));

        $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertStatus(409);

        // Solo permanece el movimiento pre-existente; nada nuevo se agregó.
        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame('0.00', $this->canon($productoUno->fresh()->producto_stock_actual));
        $this->assertSame('4.00', $this->canon($productoDos->fresh()->producto_stock_actual));
        $this->assertDatabaseCount('pedido_novedades', 0);
        $this->assertSame(0, PedidoEvento::query()->whereIn('event_type', ['novedad_abierta', 'pedido_recibido', 'recibido_con_novedad'])->count());
        $this->assertSame(0, PedidoOperacion::query()->where('command', 'confirmar_recepcion')->count());
        $this->assertSame('borrador', PedidoRecepcion::query()->findOrFail($recepcion['recepcion_id'])->estado);
    }

    public function test_producto_nuevo_incompleto_bloquea_solo_su_linea_y_no_toca_el_catalogo(): void
    {
        $productoOk = $this->productoConSku('SKU-FICHA-OK');

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-FICHA-OK', 'overrides' => ['producto_id' => $productoOk->producto_id]],
            ['sku' => 'SKU-FICHA-NUEVA'],
        ]);

        $lineaOk = $this->lineaDe($pedido, 1);
        $lineaNueva = $this->lineaDe($pedido, 2);

        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $lineaOk->linea_id);
        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $lineaNueva->linea_id);

        $respuesta = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $respuesta->assertJsonPath('data.posted_lines.0.line_id', $lineaOk->linea_id)
            ->assertJsonPath('data.blocked_lines.0.line_id', $lineaNueva->linea_id)
            ->assertJsonPath('data.blocked_lines.0.reason', 'ficha_incompleta')
            ->assertJsonPath('data.blocked_lines.0.issue_id', null);

        $this->assertDatabaseCount('productos', 1); // catálogo intacto
        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame('conforme', $lineaNueva->fresh()->estado);
        $this->assertSame('parcial_con_novedad', $pedido->fresh()->estado);
        $this->assertDatabaseCount('pedido_novedades', 0);

        // Completar ficha y confirmar en una segunda sesión.
        $categoriaId = $this->categoriaId();

        $this->patchJson(
            "/api/web/inventory-orders/{$pedido->uuid}/lines/{$lineaNueva->linea_id}/catalog-draft",
            [
                'version' => $lineaNueva->fresh()->versionActual(),
                'categoria_propuesta_id' => $categoriaId,
                'unidad' => 'caja',
                'alerta_minima_propuesta' => '0',
            ],
        )->assertOk();

        $segunda = $this->abrirRecepcion($receptor, $pedido)->assertStatus(201);
        $segundaId = (int) $segunda->json('data.recepcion_id');
        $this->contarConforme($receptor, $pedido, $segundaId, $lineaNueva->linea_id);

        $this->confirmar($receptor, $pedido, $segundaId)->assertOk();

        $productoNuevo = Producto::query()->where('producto_sku', 'SKU-FICHA-NUEVA')->sole();
        $this->assertSame($categoriaId, (int) $productoNuevo->categoria_id);
        $this->assertSame('caja', $productoNuevo->producto_unidad_medida);
        $this->assertSame('0.00', $this->canon($productoNuevo->producto_alerta_stock_minimo));
        $this->assertSame('4.00', $this->canon($productoNuevo->producto_stock_actual));
        $this->assertSame(2, TransaccionInventario::query()->count());
        $this->assertSame('recibido', $pedido->fresh()->estado);
    }

    public function test_dos_lineas_con_el_mismo_sku_nuevo_compatible_crean_un_producto_y_suman(): void
    {
        $categoriaId = $this->categoriaId();

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            [
                'sku' => 'SKU-COMPARTIDO',
                'cantidad' => '4.00',
                'overrides' => ['categoria_propuesta_id' => $categoriaId, 'unidad' => 'caja', 'descripcion_confirmada' => 'PRODUCTO COMPARTIDO'],
            ],
            [
                'sku' => 'SKU-COMPARTIDO',
                'cantidad' => '2.00',
                'overrides' => ['categoria_propuesta_id' => $categoriaId, 'unidad' => 'caja', 'descripcion_confirmada' => 'PRODUCTO COMPARTIDO'],
            ],
        ]);

        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $this->lineaDe($pedido, 1)->linea_id, '4.00');
        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $this->lineaDe($pedido, 2)->linea_id, '2.00');

        $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $producto = Producto::query()->where('producto_sku', 'SKU-COMPARTIDO')->sole();
        $this->assertSame('6.00', $this->canon($producto->producto_stock_actual));
        $this->assertSame(2, TransaccionInventario::query()->where('producto_id', $producto->producto_id)->count());
        $this->assertSame('6.00', $this->canon(
            BodegaProducto::query()
                ->where('bodega_id', $pedido->bodega_id)
                ->where('producto_id', $producto->producto_id)
                ->value('cantidad'),
        ));
    }

    public function test_fichas_incompatibles_para_el_mismo_sku_nuevo_es_409(): void
    {
        $categoriaUno = $this->categoriaId();
        $categoriaDos = $this->categoriaId();

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            [
                'sku' => 'SKU-INCOMPATIBLE',
                'overrides' => ['categoria_propuesta_id' => $categoriaUno, 'unidad' => 'caja'],
            ],
            [
                'sku' => 'SKU-INCOMPATIBLE',
                'overrides' => ['categoria_propuesta_id' => $categoriaDos, 'unidad' => 'litro'],
            ],
        ]);

        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $this->lineaDe($pedido, 1)->linea_id);
        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $this->lineaDe($pedido, 2)->linea_id);

        $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])
            ->assertStatus(409)
            ->assertJsonPath('requiere_revision_humana', true);

        $this->assertDatabaseCount('productos', 0);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertSame('borrador', PedidoRecepcion::query()->findOrFail($recepcion['recepcion_id'])->estado);
    }

    public function test_sku_existente_se_enlaza_sin_sobrescribir_la_ficha_global(): void
    {
        $producto = $this->productoConSku('SKU-ENLAZA', '0.00', [
            'producto_nombre' => 'NOMBRE GLOBAL INTOCABLE',
            'producto_precio_costo' => 99,
        ]);

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-ENLAZA', 'descripcion' => 'DESCRIPCIÓN DE FACTURA DIFERENTE'],
        ]);

        $linea = $this->lineaDe($pedido, 1);
        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $linea->linea_id);

        $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $fresco = $producto->fresh();
        $this->assertSame('NOMBRE GLOBAL INTOCABLE', $fresco->producto_nombre);
        $this->assertSame('99.00', $this->canon($fresco->producto_precio_costo));
        $this->assertSame($producto->producto_id, (int) $linea->fresh()->producto_id);
        $this->assertDatabaseCount('productos', 1);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
    }

    public function test_unique_de_sku_al_crear_se_traduce_en_409_sin_sobrescribir(): void
    {
        $productoIntocable = $this->productoConSku('SKU-OTRO', '0.00', ['producto_nombre' => 'FICHA GLOBAL INTOCABLE']);
        $categoriaId = $this->categoriaId();

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-CARRERA', 'overrides' => ['categoria_propuesta_id' => $categoriaId, 'unidad' => 'caja']],
        ]);

        // Se simula que otra operación ganó la carrera por el unique del SKU:
        // el INSERT del catálogo aborta la transacción completa.
        Producto::creating(function (Producto $nuevo): void {
            throw new QueryException(
                'sqlite',
                'insert into "productos" ("producto_sku") values (?)',
                [$nuevo->producto_sku],
                new PDOException('UNIQUE constraint failed: productos.producto_sku'),
            );
        });

        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $this->lineaDe($pedido, 1)->linea_id);

        $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])
            ->assertStatus(409)
            ->assertJsonPath('requiere_revision_humana', true);

        $this->assertSame('FICHA GLOBAL INTOCABLE', $productoIntocable->fresh()->producto_nombre);
        $this->assertDatabaseCount('productos', 1);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertSame('borrador', PedidoRecepcion::query()->findOrFail($recepcion['recepcion_id'])->estado);
    }

    public function test_capacidad_maxima_bloquea_el_ingreso_sin_movimientos(): void
    {
        $producto = $this->productoConSku('SKU-CAP', '3.00', ['capacidad_maxima' => 5]);

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-CAP', 'overrides' => ['producto_id' => $producto->producto_id]],
        ]);

        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $this->lineaDe($pedido, 1)->linea_id);

        $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])
            ->assertStatus(409)
            ->assertJsonPath('producto_id', $producto->producto_id);

        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertSame('3.00', $this->canon($producto->fresh()->producto_stock_actual));
    }

    public function test_confirmacion_sin_lineas_ingresables_responde_desglose_explicito(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-CERO'],
        ]);

        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $this->lineaDe($pedido, 1), [
            'conteo_total' => '0.00',
            'conteo_aptos' => '0.00',
            'estado_condicion' => 'conforme',
        ])->assertOk();

        $respuesta = $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $respuesta->assertJsonPath('data.posted_lines', [])
            ->assertJsonPath('data.blocked_lines.0.reason', 'faltante')
            ->assertJsonPath('data.closed_without_stock', [])
            ->assertJsonPath('data.resulting_state', 'parcial_con_novedad');

        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_receptor_sin_empleado_activo_no_puede_confirmar(): void
    {
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([['sku' => 'SKU-ACTOR']]);
        $linea = $this->lineaDe($pedido, 1);

        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $linea->linea_id);

        $otro = $this->usuario('auxiliar_bodega'); // sin empleado activo

        $this->confirmar($otro, $pedido, $recepcion['recepcion_id'])->assertStatus(403);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_actor_de_auditoria_sale_de_auth_y_no_del_payload(): void
    {
        $producto = $this->productoConSku('SKU-ACTOR-2');

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-ACTOR-2', 'overrides' => ['producto_id' => $producto->producto_id]],
        ]);

        $linea = $this->lineaDe($pedido, 1);
        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $linea->linea_id);

        $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'], [
            'actor_user_id' => 999,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('actor_user_id');

        $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $transaccion = TransaccionInventario::query()->sole();
        $this->assertSame((int) $receptor->id, (int) $transaccion->usuario_id);
        $this->assertSame(
            (int) $receptor->id,
            (int) PedidoOperacion::query()->where('command', 'confirmar_recepcion')->value('actor_id'),
        );
        $this->assertSame(
            (int) $receptor->id,
            (int) PedidoEvento::query()->where('event_type', 'pedido_recibido')->value('actor_user_id'),
        );
    }

    public function test_conteo_y_ficha_tras_ingreso_quedan_bloqueados(): void
    {
        $producto = $this->productoConSku('SKU-BLOQUEADA');

        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-BLOQUEADA', 'overrides' => ['producto_id' => $producto->producto_id]],
            ['sku' => 'SKU-BLOQUEADA-2'],
        ]);

        $linea = $this->lineaDe($pedido, 1);
        $lineaDos = $this->lineaDe($pedido, 2);

        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $linea->linea_id);
        $this->contarConforme($receptor, $pedido, $recepcion['recepcion_id'], $lineaDos->linea_id, '3.00');
        $this->confirmar($receptor, $pedido, $recepcion['recepcion_id'])->assertOk();

        $this->assertSame('parcial_con_novedad', $pedido->fresh()->estado);

        $this->contar($receptor, $pedido, $recepcion['recepcion_id'], $linea, [
            'observacion_version' => 2,
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'conforme',
        ])->assertStatus(409);

        // La observación confirmada es inmutable (R11).
        $this->assertSame('4.00', $this->canon(
            PedidoRecepcionLinea::query()->where('pedido_linea_id', $linea->linea_id)->value('conteo_total'),
        ));

        // La ficha de una línea ya ingresada queda congelada (409).
        $this->patchJson(
            "/api/web/inventory-orders/{$pedido->uuid}/lines/{$linea->linea_id}/catalog-draft",
            ['version' => $linea->fresh()->versionActual(), 'unidad' => 'caja'],
        )->assertStatus(409);
    }
}
