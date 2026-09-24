<?php

namespace Tests\Feature;

use App\Filament\Resources\CategoriaResource;
use App\Filament\Resources\ProductoResource;
use App\Models\Bodega;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryDeletionContainmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_disables_product_and_category_deletion(): void
    {
        $categoria = $this->category();
        $producto = $this->product($categoria);

        $this->assertFalse(ProductoResource::canDelete($producto));
        $this->assertFalse(ProductoResource::canDeleteAny());
        $this->assertFalse(CategoriaResource::canDelete($categoria));
        $this->assertFalse(CategoriaResource::canDeleteAny());
    }

    public function test_database_restricts_category_deletion_when_it_has_products(): void
    {
        $categoria = $this->category();
        $this->product($categoria);

        try {
            DB::table('categorias')->where('categoria_id', $categoria->categoria_id)->delete();
            $this->fail('La FK debía impedir el borrado de la categoría.');
        } catch (QueryException) {
            $this->assertDatabaseHas('categorias', ['categoria_id' => $categoria->categoria_id]);
            $this->assertDatabaseCount('productos', 1);
        }
    }

    public function test_api_disables_hard_delete_even_for_a_product_without_movements(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'permisos' => ['inventario.write'],
        ]));
        $producto = $this->product($this->category());

        $this->deleteJson("/api/productos/{$producto->producto_id}")
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'La eliminación física de productos está deshabilitada para proteger el ledger de inventario.',
            );

        $this->assertDatabaseHas('productos', ['producto_id' => $producto->producto_id]);
    }

    public function test_database_restricts_deletion_of_a_warehouse_referenced_by_ledger(): void
    {
        $producto = $this->product($this->category());
        $bodega = Bodega::create([
            'nombre' => 'Bodega trazable',
            'tipo' => 'estandar',
        ]);
        DB::transaction(function () use ($producto, $bodega): void {
            TransaccionInventario::create([
                'producto_id' => $producto->producto_id,
                'bodega_id' => $bodega->bodega_id,
                'transaccion_tipo' => 'ingreso',
                'transaccion_cantidad' => 1,
                'transaccion_motivo' => 'Prueba de trazabilidad',
            ]);
        });

        try {
            DB::table('bodegas')->where('bodega_id', $bodega->bodega_id)->delete();
            $this->fail('La FK debía impedir el borrado de la bodega referenciada.');
        } catch (QueryException) {
            $this->assertDatabaseHas('bodegas', ['bodega_id' => $bodega->bodega_id]);
            $this->assertDatabaseHas('transaccion_inventarios', ['bodega_id' => $bodega->bodega_id]);
        }
    }

    public function test_database_restricts_deletion_of_a_warehouse_referenced_only_by_balance(): void
    {
        $product = $this->product($this->category());
        $warehouse = Bodega::create([
            'nombre' => 'Bodega con saldo',
            'tipo' => 'estandar',
        ]);
        DB::table('bodega_producto')->insert([
            'bodega_id' => $warehouse->bodega_id,
            'producto_id' => $product->producto_id,
            'cantidad' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('bodegas')->where('bodega_id', $warehouse->bodega_id)->delete();
            $this->fail('La FK del balance debía impedir el borrado de la bodega.');
        } catch (QueryException) {
            $this->assertDatabaseHas('bodegas', ['bodega_id' => $warehouse->bodega_id]);
            $this->assertDatabaseHas('bodega_producto', [
                'bodega_id' => $warehouse->bodega_id,
                'producto_id' => $product->producto_id,
            ]);
        }
    }

    private function category(): Categoria
    {
        return Categoria::create([
            'categoria_nombre' => 'Repuestos',
            'categoria_tipo' => 'repuesto',
        ]);
    }

    private function product(Categoria $categoria): Producto
    {
        return Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'PROTECTED-1',
            'producto_nombre' => 'Producto protegido',
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => 0,
        ]);
    }
}
