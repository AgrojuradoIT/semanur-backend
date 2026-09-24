<?php

namespace Tests\Feature\InventoryOrders;

use App\Models\PedidoAlerta;
use App\Models\PedidoDocumento;
use App\Models\PedidoOperacion;
use App\Models\Producto;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\InventoryOrders\Concerns\PedidoFixtures;
use Tests\TestCase;

class PedidoInventarioSchemaTest extends TestCase
{
    use PedidoFixtures, RefreshDatabase;

    public function test_u1_tables_and_ledger_link_exist(): void
    {
        $tables = [
            'pedidos_inventario',
            'pedido_inventario_lineas',
            'pedido_documentos',
            'pedido_ocr_intentos',
            'pedido_recepciones',
            'pedido_recepcion_lineas',
            'pedido_novedades',
            'pedido_autorizaciones',
            'pedido_operaciones',
            'pedido_eventos',
            'pedido_alertas',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Falta la tabla {$table}");
        }

        $this->assertTrue(Schema::hasColumn('transaccion_inventarios', 'pedido_linea_id'));
        $this->assertTrue(Schema::hasColumn('transaccion_inventarios', 'transaccion_referencia_id'));
    }

    public function test_line_order_is_unique_per_order(): void
    {
        $pedido = $this->pedido();
        $this->linea($pedido, 1);

        $this->expectException(QueryException::class);

        $this->linea($pedido, 1);
    }

    public function test_two_orders_may_reuse_the_same_order_index(): void
    {
        $primero = $this->pedido();
        $segundo = $this->pedido();

        $this->linea($primero, 1);
        $this->linea($segundo, 1);

        $this->assertSame(2, DB::table('pedido_inventario_lineas')->where('orden', 1)->count());
    }

    public function test_unique_pedido_linea_id_accepts_multiple_nulls_and_rejects_duplicates(): void
    {
        $producto = $this->producto();
        $pedido = $this->pedido();
        $linea = $this->linea($pedido);

        foreach ([1, 2, 3] as $unused) {
            $this->insertarTransaccion($producto, null);
        }

        $this->assertSame(
            3,
            DB::table('transaccion_inventarios')->whereNull('pedido_linea_id')->count(),
            'Los movimientos históricos deben poder quedar con pedido_linea_id NULL.',
        );

        $this->insertarTransaccion($producto, $linea->linea_id);

        $this->expectException(QueryException::class);
        $this->insertarTransaccion($producto, $linea->linea_id);
    }

    public function test_bodega_referenced_by_order_cannot_be_deleted(): void
    {
        $bodega = $this->bodega();
        $this->pedido($bodega);

        $this->assertDeleteBlocked('bodegas', 'bodega_id', $bodega->bodega_id);
    }

    public function test_order_with_lines_cannot_be_deleted_at_db_level(): void
    {
        $pedido = $this->pedido();
        $this->linea($pedido);

        $this->assertDeleteBlocked('pedidos_inventario', 'pedido_id', $pedido->pedido_id);
    }

    public function test_line_referenced_by_reception_line_cannot_be_deleted(): void
    {
        $pedido = $this->pedido();
        $linea = $this->linea($pedido);
        $recepcion = $this->recepcion($pedido);

        DB::table('pedido_recepcion_lineas')->insert([
            'recepcion_id' => $recepcion->recepcion_id,
            'pedido_linea_id' => $linea->linea_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDeleteBlocked('pedido_inventario_lineas', 'linea_id', $linea->linea_id);
    }

    public function test_document_referenced_by_ocr_attempt_cannot_be_deleted(): void
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

        DB::table('pedido_ocr_intentos')->insert([
            'documento_id' => $documento->documento_id,
            'proveedor' => 'fake',
            'clave_trabajo' => 'u1-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDeleteBlocked('pedido_documentos', 'documento_id', $documento->documento_id);
    }

    public function test_idempotent_operations_are_unique_per_actor_command_and_client_id(): void
    {
        $actor = $this->usuario('contabilidad');
        $operation = [
            'actor_id' => $actor->id,
            'command' => 'publish',
            'client_operation_id' => (string) \Illuminate\Support\Str::uuid(),
            'canonical_request_hash' => str_repeat('b', 64),
        ];

        PedidoOperacion::create($operation);

        $this->expectException(QueryException::class);

        PedidoOperacion::create($operation);
    }

    public function test_alert_is_unique_per_event_and_recipient(): void
    {
        $destinatario = $this->usuario('auxiliar_bodega');

        $alerta = [
            'event_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'destinatario_user_id' => $destinatario->id,
            'tipo' => 'pedido_publicado',
        ];

        PedidoAlerta::create($alerta);

        $this->expectException(QueryException::class);

        PedidoAlerta::create($alerta);
    }

    private function insertarTransaccion(Producto $producto, ?int $pedidoLineaId): void
    {
        DB::table('transaccion_inventarios')->insert([
            'producto_id' => $producto->producto_id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => '1.00',
            'transaccion_motivo' => 'compra',
            'pedido_linea_id' => $pedidoLineaId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertDeleteBlocked(string $table, string $column, mixed $value): void
    {
        try {
            DB::table($table)->where($column, $value)->delete();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("El DELETE sobre {$table}.{$column} debía ser rechazado por la FK RESTRICT.");
    }
}
