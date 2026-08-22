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

class ProductoCatalogMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_clamps_pagination_and_calculates_metrics_in_one_aggregate(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'permisos' => ['inventario.read'],
        ]));

        $categoria = Categoria::create([
            'categoria_nombre' => 'Insumos',
            'categoria_tipo' => 'insumo',
        ]);

        $this->product($categoria, 'METRIC-1', 0, 0, 100);
        $this->product($categoria, 'METRIC-2', 5, 5, 10);
        $this->product($categoria, 'METRIC-3', 6, 5, 20);

        $this->getJson('/api/productos?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('metrics.total_products', 3)
            ->assertJsonPath('metrics.low_stock', 1)
            ->assertJsonPath('metrics.total_value', 170);

        $this->getJson('/api/productos?per_page=10000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 250);
    }

    public function test_low_stock_filter_is_applied_before_pagination(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'permisos' => ['inventario.read'],
        ]));
        $categoria = Categoria::create([
            'categoria_nombre' => 'Filtros',
            'categoria_tipo' => 'repuesto',
        ]);

        for ($index = 0; $index < 15; $index++) {
            $this->product($categoria, sprintf('A-NORMAL-%02d', $index), 10, 5, 1);
        }
        $lowStock = $this->product($categoria, 'Z-LOW-STOCK', 2, 5, 1);

        $response = $this->getJson('/api/productos?low_stock=1&per_page=10');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.producto_id', $lowStock->producto_id);
    }

    public function test_catalog_and_detail_expose_warehouse_balances(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'permisos' => ['inventario.read'],
        ]));
        $category = Categoria::create([
            'categoria_nombre' => 'Warehouse contract',
            'categoria_tipo' => 'repuesto',
        ]);
        $product = $this->product($category, 'WH-BALANCE', 7, 1, 1);
        $warehouse = Bodega::create(['nombre' => 'Bodega A', 'tipo' => 'estandar']);
        DB::table('bodega_producto')->insert([
            'bodega_id' => $warehouse->bodega_id,
            'producto_id' => $product->producto_id,
            'cantidad' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/productos?q=WH-BALANCE')
            ->assertOk()
            ->assertJsonPath('data.0.bodegas.0.bodega_id', $warehouse->bodega_id)
            ->assertJsonPath('data.0.bodegas.0.pivot.cantidad', 7);

        $this->getJson("/api/productos/{$product->producto_id}")
            ->assertOk()
            ->assertJsonPath('bodegas.0.bodega_id', $warehouse->bodega_id)
            ->assertJsonPath('bodegas.0.pivot.cantidad', 7);
    }

    private function product(
        Categoria $categoria,
        string $sku,
        float $stock,
        float $minimum,
        float $cost,
    ): Producto {
        return Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => $sku,
            'producto_nombre' => $sku,
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => $stock,
            'producto_alerta_stock_minimo' => $minimum,
            'producto_precio_costo' => $cost,
        ]);
    }
}
