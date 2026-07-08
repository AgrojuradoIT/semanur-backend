<?php

namespace Tests\Unit;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransaccionInventarioObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingreso_transaction_increments_product_stock(): void
    {
        $usuario = User::factory()->create();
        $producto = $this->crearProducto(10);

        TransaccionInventario::create([
            'producto_id' => $producto->producto_id,
            'usuario_id' => $usuario->id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => 2,
            'transaccion_motivo' => 'ajuste',
        ]);

        $this->assertSame(12.0, (float) $producto->fresh()->producto_stock_actual);
    }

    public function test_salida_transaction_decrements_product_stock(): void
    {
        $usuario = User::factory()->create();
        $producto = $this->crearProducto(10);

        TransaccionInventario::create([
            'producto_id' => $producto->producto_id,
            'usuario_id' => $usuario->id,
            'transaccion_tipo' => 'salida',
            'transaccion_cantidad' => 3,
            'transaccion_motivo' => 'consumo_ot',
        ]);

        $this->assertSame(7.0, (float) $producto->fresh()->producto_stock_actual);
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
            'producto_nombre' => 'Producto prueba',
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => $stockInicial,
            'producto_alerta_stock_minimo' => 1,
            'producto_precio_costo' => 1000,
        ]);
    }
}

