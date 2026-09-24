<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\InvariantePedidoException;
use App\Domain\InventoryOrders\LineaEstado;
use App\Domain\InventoryOrders\OcrIntentoEstado;
use App\Domain\InventoryOrders\PedidoEstado;
use App\Domain\InventoryOrders\RecepcionEstado;
use App\Domain\InventoryOrders\TransicionInvalidaException;
use App\Domain\InventoryOrders\VersionDesactualizadaException;
use App\DTO\InventoryOrders\DecimalQuantity;
use App\Models\PedidoAlerta;
use App\Models\PedidoAutorizacion;
use App\Models\PedidoDocumento;
use App\Models\PedidoEvento;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoNovedad;
use App\Models\PedidoOcrIntento;
use App\Models\PedidoOperacion;
use App\Models\PedidoRecepcionLinea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\InventoryOrders\Concerns\PedidoFixtures;
use Tests\TestCase;

class PedidoInventarioModeloTest extends TestCase
{
    use PedidoFixtures, RefreshDatabase;

    public function test_pedido_transitions_follow_whitelist_and_increment_version(): void
    {
        $pedido = $this->pedido();

        $this->assertSame(PedidoEstado::BORRADOR, $pedido->estado);
        $this->assertSame(1, $pedido->versionActual());

        $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION);
        $this->assertSame(PedidoEstado::PENDIENTE_RECEPCION, $pedido->fresh()->estado);
        $this->assertSame(2, $pedido->fresh()->versionActual());

        $pedido->transicionarA(PedidoEstado::EN_VALIDACION);
        $this->assertSame(3, $pedido->fresh()->versionActual());
    }

    public function test_pedido_rejects_unlisted_transition(): void
    {
        $pedido = $this->pedido();

        $this->expectException(TransicionInvalidaException::class);

        $pedido->transicionarA(PedidoEstado::RECIBIDO);
    }

    public function test_direct_estado_update_is_guarded(): void
    {
        $pedido = $this->pedido();
        $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION);

        $this->expectException(TransicionInvalidaException::class);

        $pedido->update(['estado' => PedidoEstado::RECIBIDO]);
    }

    public function test_stale_version_is_rejected_and_correct_version_is_accepted(): void
    {
        $pedido = $this->pedido();

        try {
            $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION, [], 99);
            $this->fail('La versión obsoleta debía rechazarse.');
        } catch (VersionDesactualizadaException) {
            $this->assertSame(PedidoEstado::BORRADOR, $pedido->fresh()->estado);
        }

        $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION, [], 1);

        $this->assertSame(PedidoEstado::PENDIENTE_RECEPCION, $pedido->fresh()->estado);
    }

    public function test_expected_quantity_is_frozen_after_publish(): void
    {
        $pedido = $this->pedido();
        $linea = $this->linea($pedido, 1, '4.00');

        $linea->update(['cantidad_esperada' => '5.00']);
        $this->assertSame('5.00', DecimalQuantity::normalize($linea->fresh()->cantidad_esperada));

        $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION);

        $this->expectException(TransicionInvalidaException::class);

        $linea->fresh()->update(['cantidad_esperada' => '4.00']);
    }

    public function test_line_transitions_follow_whitelist(): void
    {
        $pedido = $this->pedido();
        $linea = $this->linea($pedido);

        try {
            $linea->transicionarA(LineaEstado::RECIBIDA);
            $this->fail('pendiente -> recibida debía rechazarse.');
        } catch (TransicionInvalidaException) {
            $this->addToAssertionCount(1);
        }

        $linea->transicionarA(LineaEstado::CONFORME);
        $this->assertSame(LineaEstado::CONFORME, $linea->fresh()->estado);

        $linea->transicionarA(LineaEstado::NOVEDAD);
        $linea->transicionarA(LineaEstado::CONFORME);

        $this->assertSame(LineaEstado::CONFORME, $linea->fresh()->estado);
        $this->assertSame(4, $linea->fresh()->versionActual());
    }

    public function test_invalid_expected_quantity_is_rejected_by_the_model(): void
    {
        $pedido = $this->pedido();

        $this->expectException(InvariantePedidoException::class);

        PedidoInventarioLinea::create([
            'pedido_id' => $pedido->pedido_id,
            'orden' => 1,
            'sku_confirmado' => 'SKU-MAL',
            'cantidad_esperada' => '4.001',
        ]);
    }

    public function test_recepcion_transition_is_one_way(): void
    {
        $recepcion = $this->recepcion($this->pedido());

        $recepcion->transicionarA(RecepcionEstado::CONFIRMADA);
        $this->assertTrue($recepcion->fresh()->estaConfirmada());

        $this->expectException(TransicionInvalidaException::class);

        $recepcion->fresh()->transicionarA(RecepcionEstado::BORRADOR);
    }

    public function test_recepcion_linea_ranges_and_immutability(): void
    {
        $pedido = $this->pedido();
        $linea = $this->linea($pedido);
        $recepcion = $this->recepcion($pedido);

        $observacion = PedidoRecepcionLinea::create([
            'recepcion_id' => $recepcion->recepcion_id,
            'pedido_linea_id' => $linea->linea_id,
            'conteo_total' => '5.00',
            'conteo_aptos' => '3.00',
        ]);

        $this->assertSame('3.00', $observacion->conteo_aptos);

        try {
            PedidoRecepcionLinea::create([
                'recepcion_id' => $recepcion->recepcion_id,
                'pedido_linea_id' => $linea->linea_id,
                'conteo_total' => '3.00',
                'conteo_aptos' => '4.00',
            ]);
            $this->fail('conteo_aptos > conteo_total debía rechazarse.');
        } catch (InvariantePedidoException) {
            $this->addToAssertionCount(1);
        } catch (\Illuminate\Database\QueryException) {
            $this->addToAssertionCount(1);
        }

        $observacion->update(['count_confirmed' => true]);

        try {
            $observacion->update(['conteo_total' => '9.00']);
            $this->fail('La observación confirmada debía ser inmutable.');
        } catch (TransicionInvalidaException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_observation_is_immutable_once_recepcion_is_confirmed(): void
    {
        $pedido = $this->pedido();
        $linea = $this->linea($pedido);
        $recepcion = $this->recepcion($pedido);

        $observacion = PedidoRecepcionLinea::create([
            'recepcion_id' => $recepcion->recepcion_id,
            'pedido_linea_id' => $linea->linea_id,
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
        ]);

        $recepcion->transicionarA(RecepcionEstado::CONFIRMADA);

        $this->expectException(TransicionInvalidaException::class);

        $observacion->update(['estado_condicion' => 'dañado']);
    }

    public function test_ocr_attempt_transitions(): void
    {
        $pedido = $this->pedido();
        $documento = PedidoDocumento::create([
            'pedido_id' => $pedido->pedido_id,
            'rol' => 'siigo',
            'storage_key' => 'private/siigo/u1.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64),
        ]);

        $intento = PedidoOcrIntento::create([
            'documento_id' => $documento->documento_id,
            'documento_revision' => 1,
            'proveedor' => 'fake',
            'clave_trabajo' => 'ocr-'.Str::uuid(),
        ]);

        $intento->transicionarA(OcrIntentoEstado::PROCESANDO);
        $intento->transicionarA(OcrIntentoEstado::COMPLETADO);
        $intento->transicionarA(OcrIntentoEstado::OBSOLETO);

        $this->assertSame(OcrIntentoEstado::OBSOLETO, $intento->fresh()->estado);

        $this->expectException(TransicionInvalidaException::class);

        $intento->transicionarA(OcrIntentoEstado::PROCESANDO);
    }

    public function test_published_order_cannot_be_deleted_but_draft_can(): void
    {
        $pedido = $this->pedido();
        $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION);

        try {
            $pedido->delete();
            $this->fail('Un pedido publicado no debía eliminarse.');
        } catch (TransicionInvalidaException) {
            $this->addToAssertionCount(1);
        }

        $borrador = $this->pedido();
        $borrador->delete();

        $this->assertDatabaseMissing('pedidos_inventario', ['pedido_id' => $borrador->pedido_id]);
    }

    public function test_evidence_records_cannot_be_physically_deleted(): void
    {
        $pedido = $this->pedido();
        $linea = $this->linea($pedido);
        $recepcion = $this->recepcion($pedido);

        $documento = PedidoDocumento::create([
            'pedido_id' => $pedido->pedido_id,
            'rol' => 'factura',
            'storage_key' => 'private/factura/u1.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
            'sha256' => str_repeat('c', 64),
        ]);

        $intento = PedidoOcrIntento::create([
            'documento_id' => $documento->documento_id,
            'proveedor' => 'fake',
            'clave_trabajo' => 'ocr-delete-'.Str::uuid(),
        ]);

        $observacion = PedidoRecepcionLinea::create([
            'recepcion_id' => $recepcion->recepcion_id,
            'pedido_linea_id' => $linea->linea_id,
            'conteo_total' => '1.00',
        ]);

        $novedad = PedidoNovedad::create([
            'pedido_linea_id' => $linea->linea_id,
            'tipo' => 'faltante',
            'motivo' => 'Faltó mercancía',
        ]);

        $evento = PedidoEvento::create([
            'pedido_id' => $pedido->pedido_id,
            'event_type' => 'pedido_creado',
            'datos_despues' => ['estado' => PedidoEstado::BORRADOR],
        ]);

        $operacion = PedidoOperacion::create([
            'actor_id' => $this->usuario()->id,
            'command' => 'create',
            'client_operation_id' => (string) Str::uuid(),
            'canonical_request_hash' => str_repeat('d', 64),
        ]);

        foreach ([$documento, $intento, $recepcion, $observacion, $novedad, $evento, $operacion] as $registro) {
            try {
                $registro->delete();
                $this->fail(get_class($registro).' no debía eliminarse físicamente.');
            } catch (TransicionInvalidaException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_autorizacion_requires_d1_gate_and_is_immutable(): void
    {
        $pedido = $this->pedido();
        $linea = $this->linea($pedido);
        $novedad = PedidoNovedad::create([
            'pedido_linea_id' => $linea->linea_id,
            'tipo' => 'faltante',
            'motivo' => 'Faltó una unidad',
        ]);

        config([
            'inventory_orders.discrepancy_authorization_enabled' => false,
            'inventory_orders.discrepancy_authorizer_roles' => [],
        ]);

        try {
            $this->crearAutorizacion($novedad);
            $this->fail('D1 debía impedir la autorización con el gate cerrado.');
        } catch (InvariantePedidoException) {
            $this->addToAssertionCount(1);
        }

        config([
            'inventory_orders.discrepancy_authorization_enabled' => true,
            'inventory_orders.discrepancy_authorizer_roles' => ['auxiliar_bodega'],
        ]);

        $autorizacion = $this->crearAutorizacion($novedad);
        $this->assertTrue($autorizacion->estaVigente());

        try {
            $autorizacion->update(['cantidad_autorizada' => '1.00']);
            $this->fail('La autorización debía ser inmutable.');
        } catch (TransicionInvalidaException) {
            $this->addToAssertionCount(1);
        }

        $autorizacion = $autorizacion->fresh();
        $recepcion = $this->recepcion($pedido);
        $autorizacion->consumir($recepcion);

        $this->assertSame(PedidoAutorizacion::ESTADO_CONSUMIDA, $autorizacion->fresh()->estado);

        $this->expectException(TransicionInvalidaException::class);

        $autorizacion->fresh()->consumir($recepcion);
    }

    public function test_evento_content_is_append_only_and_alerta_only_allows_read_mark(): void
    {
        $pedido = $this->pedido();

        $evento = PedidoEvento::create([
            'pedido_id' => $pedido->pedido_id,
            'event_type' => 'pedido_publicado',
            'datos_despues' => ['estado' => PedidoEstado::PENDIENTE_RECEPCION],
        ]);

        $this->assertNotNull($evento->event_uuid);

        $evento->update(['estado_despacho' => 'despachado']);
        $this->assertSame('despachado', $evento->fresh()->estado_despacho);

        try {
            $evento->update(['datos_despues' => ['estado' => 'alterado']]);
            $this->fail('El contenido de auditoría debía ser append-only.');
        } catch (TransicionInvalidaException) {
            $this->addToAssertionCount(1);
        }

        $alerta = PedidoAlerta::create([
            'event_uuid' => (string) Str::uuid(),
            'destinatario_user_id' => $this->usuario('auxiliar_bodega')->id,
            'pedido_id' => $pedido->pedido_id,
            'tipo' => 'pedido_publicado',
        ]);

        $alerta->marcarLeida();
        $this->assertNotNull($alerta->fresh()->leida_at);

        $this->expectException(TransicionInvalidaException::class);

        $alerta->update(['tipo' => 'otro_tipo']);
    }

    private function crearAutorizacion(PedidoNovedad $novedad): PedidoAutorizacion
    {
        return PedidoAutorizacion::create([
            'novedad_id' => $novedad->novedad_id,
            'actor_user_id' => $this->usuario('auxiliar_bodega')->id,
            'rol_snapshot' => 'auxiliar_bodega',
            'cantidad_autorizada' => '3.00',
            'order_version' => 2,
            'line_version' => 2,
            'count_revision' => 1,
            'contexto_hash' => str_repeat('e', 64),
            'diferencia_cantidad' => '-1.00',
            'motivo' => 'Faltante confirmado',
            'aceptacion_responsabilidad' => 'Acepto la diferencia',
        ]);
    }
}
