<?php

namespace Tests\Feature\Api;

use App\Models\Bodega;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MovimientoInventarioApiControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'admin',
            'permisos' => ['movimientos'],
            'fcm_token' => 'secret-device-token',
        ]);
        Sanctum::actingAs($this->user);
    }

    public function test_transferencia_requires_origin_destination_and_operation_id(): void
    {
        $response = $this->postJson('/api/movimientos', [
            'producto_id' => $this->crearProducto(10)->producto_id,
            'transaccion_tipo' => 'transferencia',
            'transaccion_cantidad' => 2,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors([
            'client_operation_id',
            'bodega_origen_id',
            'bodega_destino_id',
        ]);
    }

    public function test_ingreso_updates_warehouse_and_global_stock_only_once(): void
    {
        $producto = $this->crearProducto(10);
        $bodega = $this->crearBodega('Principal', 'estandar');
        $operationId = (string) Str::uuid();
        $payload = [
            'client_operation_id' => $operationId,
            'producto_id' => $producto->producto_id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => 5,
            'transaccion_motivo' => 'Compra',
            'bodega_id' => $bodega->bodega_id,
        ];

        $this->postJson('/api/movimientos', $payload)
            ->assertCreated()
            ->assertJsonPath('idempotent_replay', false)
            ->assertJsonPath('nuevo_stock_global', 15);

        $this->postJson('/api/movimientos', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('nuevo_stock_global', 15);

        $this->assertSame(5.0, $this->stockBodega($bodega, $producto));
        $this->assertSame(15.0, (float) $producto->fresh()->producto_stock_actual);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
    }

    public function test_same_operation_id_with_different_payload_is_rejected(): void
    {
        $producto = $this->crearProducto(0);
        $bodega = $this->crearBodega('Principal', 'estandar');
        $operationId = (string) Str::uuid();

        $payload = [
            'client_operation_id' => $operationId,
            'producto_id' => $producto->producto_id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => 5,
            'bodega_id' => $bodega->bodega_id,
        ];
        $this->postJson('/api/movimientos', $payload)->assertCreated();

        $payload['transaccion_cantidad'] = 6;
        $this->postJson('/api/movimientos', $payload)
            ->assertStatus(409)
            ->assertJsonPath('message', 'client_operation_id ya fue utilizado con un movimiento diferente.');

        $this->assertSame(5.0, (float) $producto->fresh()->producto_stock_actual);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
    }

    public function test_salida_without_warehouse_balance_does_not_clone_global_stock(): void
    {
        $producto = $this->crearProducto(10);
        $bodega = $this->crearBodega('Principal', 'estandar');

        $this->postJson('/api/movimientos', [
            'client_operation_id' => (string) Str::uuid(),
            'producto_id' => $producto->producto_id,
            'transaccion_tipo' => 'salida',
            'transaccion_cantidad' => 2,
            'bodega_id' => $bodega->bodega_id,
        ])->assertStatus(422)->assertJsonValidationErrors(['transaccion_cantidad']);

        $this->assertSame(10.0, (float) $producto->fresh()->producto_stock_actual);
        $this->assertDatabaseMissing('bodega_producto', [
            'bodega_id' => $bodega->bodega_id,
            'producto_id' => $producto->producto_id,
        ]);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_transfer_is_auditable_and_does_not_change_global_stock(): void
    {
        $producto = $this->crearProducto(10);
        $origen = $this->crearBodega('Principal', 'estandar');
        $destino = $this->crearBodega('Recuperación', 'recuperacion');
        $this->setStockBodega($origen, $producto, 10);

        $response = $this->postJson('/api/movimientos', [
            'client_operation_id' => (string) Str::uuid(),
            'producto_id' => $producto->producto_id,
            'transaccion_tipo' => 'transferencia',
            'transaccion_cantidad' => 3,
            'transaccion_motivo' => 'Traslado auditable',
            'bodega_origen_id' => $origen->bodega_id,
            'bodega_destino_id' => $destino->bodega_id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('movimiento.bodega_origen_id', $origen->bodega_id)
            ->assertJsonPath('movimiento.bodega_destino_id', $destino->bodega_id);
        $this->assertSame(7.0, $this->stockBodega($origen, $producto));
        $this->assertSame(3.0, $this->stockBodega($destino, $producto));
        $this->assertSame(10.0, (float) $producto->fresh()->producto_stock_actual);
        $this->assertDatabaseHas('transaccion_inventarios', [
            'producto_id' => $producto->producto_id,
            'bodega_id' => null,
            'bodega_origen_id' => $origen->bodega_id,
            'bodega_destino_id' => $destino->bodega_id,
            'transaccion_tipo' => 'transferencia',
        ]);
    }

    public function test_transfer_rejects_invalid_warehouse_types_without_mutating_stock(): void
    {
        $producto = $this->crearProducto(10);
        $origen = $this->crearBodega('Recuperación', 'recuperacion');
        $destino = $this->crearBodega('Principal', 'estandar');
        $this->setStockBodega($origen, $producto, 10);

        $this->postJson('/api/movimientos', [
            'client_operation_id' => (string) Str::uuid(),
            'producto_id' => $producto->producto_id,
            'transaccion_tipo' => 'transferencia',
            'transaccion_cantidad' => 2,
            'bodega_origen_id' => $origen->bodega_id,
            'bodega_destino_id' => $destino->bodega_id,
        ])->assertStatus(422)->assertJsonValidationErrors(['bodega_destino_id']);

        $this->assertSame(10.0, $this->stockBodega($origen, $producto));
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_index_is_paginated_and_does_not_expose_user_secrets(): void
    {
        $producto = $this->crearProducto(0);
        $bodega = $this->crearBodega('Principal', 'estandar');

        foreach (range(1, 3) as $index) {
            DB::transaction(fn () => TransaccionInventario::create([
                'producto_id' => $producto->producto_id,
                'bodega_id' => $bodega->bodega_id,
                'usuario_id' => $this->user->id,
                'transaccion_tipo' => 'ingreso',
                'transaccion_cantidad' => 1,
                'transaccion_motivo' => "Ingreso {$index}",
            ]));
        }

        $response = $this->getJson('/api/movimientos?per_page=2');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('data.0.usuario.id', $this->user->id)
            ->assertJsonPath('data.0.usuario.name', $this->user->name)
            ->assertJsonMissingPath('data.0.usuario.fcm_token')
            ->assertJsonMissingPath('data.0.usuario.permisos');
    }

    private function crearProducto(float $stockInicial): Producto
    {
        $categoria = Categoria::create([
            'categoria_nombre' => 'Insumos',
            'categoria_tipo' => 'insumo',
        ]);

        return Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'SKU-'.uniqid(),
            'producto_nombre' => 'Producto test',
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => $stockInicial,
            'producto_alerta_stock_minimo' => 1,
            'producto_precio_costo' => 1000,
        ]);
    }

    private function crearBodega(string $nombre, string $tipo): Bodega
    {
        return Bodega::create(compact('nombre', 'tipo'));
    }

    private function setStockBodega(Bodega $bodega, Producto $producto, float $cantidad): void
    {
        DB::table('bodega_producto')->insert([
            'bodega_id' => $bodega->bodega_id,
            'producto_id' => $producto->producto_id,
            'cantidad' => $cantidad,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function stockBodega(Bodega $bodega, Producto $producto): float
    {
        return (float) DB::table('bodega_producto')
            ->where('bodega_id', $bodega->bodega_id)
            ->where('producto_id', $producto->producto_id)
            ->value('cantidad');
    }
}
