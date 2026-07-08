<?php

namespace Tests\Feature\Api;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventarioImportComprasTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_returns_summary_for_new_and_existing_products(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $categoria = Categoria::create([
            'categoria_nombre' => 'Insumos',
            'categoria_tipo' => 'insumo',
        ]);

        $productoExistente = Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'EXIST-1',
            'producto_nombre' => 'Producto Existente',
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => 5,
            'producto_alerta_stock_minimo' => 1,
            'producto_precio_costo' => 1000,
        ]);

        $csvContent = implode("\n", [
            'Código,Nombre,Referencia fábrica,Categoría,Saldo cantidades',
            'EXIST-1,Producto Existente,REF-A,Insumos,3',
            'NEW-1,Producto Nuevo,REF-B,Insumos,10',
        ]);

        $file = UploadedFile::fake()->createWithContent('compras.csv', $csvContent);

        $response = $this->postJson('/api/inventario/import-compras', [
            'file' => $file,
            'dry_run' => 1,
        ]);

        $response->assertOk();

        $data = $response->json();

        $this->assertEquals(2, $data['summary']['valid_skus']);
        $this->assertEquals(1, $data['summary']['existing_products_count']);
        $this->assertEquals(1, $data['summary']['new_products_count']);
        $this->assertTrue($data['requires_confirmation']);
    }

    public function test_apply_creates_products_and_movements(): void
    {
        Sanctum::actingAs($user = User::factory()->create(['role' => 'admin']));

        $categoria = Categoria::create([
            'categoria_nombre' => 'Insumos',
            'categoria_tipo' => 'insumo',
        ]);

        $productoExistente = Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'EXIST-1',
            'producto_nombre' => 'Producto Existente',
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => 5,
            'producto_alerta_stock_minimo' => 1,
            'producto_precio_costo' => 1000,
        ]);

        $csvContent = implode("\n", [
            'Código,Nombre,Referencia fábrica,Categoría,Saldo cantidades',
            'EXIST-1,Producto Existente,REF-A,Insumos,3',
            'NEW-1,Producto Nuevo,REF-B,Insumos,10',
        ]);

        $file = UploadedFile::fake()->createWithContent('compras.csv', $csvContent);

        $response = $this->postJson('/api/inventario/import-compras', [
            'file' => $file,
            'dry_run' => 0,
        ]);

        $response->assertOk();
        $data = $response->json();

        $this->assertEquals(1, $data['created_products']);
        $this->assertEquals(1, $data['updated_products']);

        $this->assertEquals(8.0, (float) $productoExistente->fresh()->producto_stock_actual);

        $this->assertDatabaseHas('productos', [
            'producto_sku' => 'NEW-1',
            'producto_nombre' => 'Producto Nuevo',
        ]);
    }
}

