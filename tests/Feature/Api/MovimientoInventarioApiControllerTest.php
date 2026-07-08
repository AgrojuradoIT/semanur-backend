<?php

namespace Tests\Feature\Api;

use App\Models\Bodega;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MovimientoInventarioApiControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_transferencia_requires_origin_and_destination_bodegas(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'permisos' => ['movimientos'],
        ]));
        $producto = $this->crearProducto(10);

        $response = $this->postJson('/api/movimientos', [
            'producto_id' => $producto->producto_id,
            'transaccion_tipo' => 'transferencia',
            'transaccion_cantidad' => 2,
            'transaccion_motivo' => 'Traslado entre bodegas',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bodega_origen_id', 'bodega_destino_id']);
    }

    public function test_ingreso_updates_bodega_and_global_stock(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'permisos' => ['movimientos'],
        ]));
        $producto = $this->crearProducto(10);
        $bodega = Bodega::create([
            'nombre' => 'Principal',
            'tipo' => 'estandar',
        ]);

        $response = $this->postJson('/api/movimientos', [
            'producto_id' => $producto->producto_id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => 5,
            'transaccion_motivo' => 'Compra',
            'bodega_id' => $bodega->bodega_id,
        ]);

        $response->assertOk();

        $cantidadEnBodega = DB::table('bodega_producto')
            ->where('bodega_id', $bodega->bodega_id)
            ->where('producto_id', $producto->producto_id)
            ->value('cantidad');

        $this->assertSame(5.0, (float) $cantidadEnBodega);
        $this->assertSame(15.0, (float) $producto->fresh()->producto_stock_actual);
    }

    public function test_transferencia_rejects_invalid_bodega_types(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'permisos' => ['movimientos'],
        ]));
        $producto = $this->crearProducto(10);

        $origen = Bodega::create([
            'nombre' => 'Bodega Recuperacion',
            'tipo' => 'recuperacion',
        ]);
        $destino = Bodega::create([
            'nombre' => 'Bodega Principal',
            'tipo' => 'estandar',
        ]);

        DB::table('bodega_producto')->insert([
            'bodega_id' => $origen->bodega_id,
            'producto_id' => $producto->producto_id,
            'cantidad' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/movimientos', [
            'producto_id' => $producto->producto_id,
            'transaccion_tipo' => 'transferencia',
            'transaccion_cantidad' => 2,
            'transaccion_motivo' => 'Traslado invalido',
            'bodega_origen_id' => $origen->bodega_id,
            'bodega_destino_id' => $destino->bodega_id,
        ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Solo se permite transferencia desde bodega estandar hacia bodega recuperacion',
            ]);
    }

    private function crearProducto(float $stockInicial): Producto
    {
        $categoria = Categoria::create([
            'categoria_nombre' => 'Insumos',
            'categoria_tipo' => 'insumo',
        ]);

        return Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'SKU-' . uniqid(),
            'producto_nombre' => 'Producto test',
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => $stockInicial,
            'producto_alerta_stock_minimo' => 1,
            'producto_precio_costo' => 1000,
        ]);
    }
}

