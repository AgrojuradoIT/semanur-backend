<?php

namespace Tests\Feature\Api;

use App\Models\Bodega;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductoSaldoInicialTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_product_without_initial_balance(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'permisos' => ['inventario.write'],
        ]);
        Sanctum::actingAs($user);

        $categoria = Categoria::create([
            'categoria_nombre' => 'Repuestos',
            'categoria_tipo' => 'repuestos',
        ]);

        $response = $this->postJson('/api/productos', [
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'SKU-TEST-001',
            'producto_nombre' => 'Filtro de Aceite',
            'producto_unidad_medida' => 'unidad',
            'producto_alerta_stock_minimo' => 5,
            'producto_precio_costo' => 25000,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('producto.producto_stock_actual', 0);

        $this->assertDatabaseHas('productos', [
            'producto_sku' => 'SKU-TEST-001',
            'producto_stock_actual' => 0,
        ]);

        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_creates_product_with_initial_balance_and_registers_ledger_transaction(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'permisos' => ['inventario.write'],
        ]);
        Sanctum::actingAs($user);

        $categoria = Categoria::create([
            'categoria_nombre' => 'Repuestos',
            'categoria_tipo' => 'repuestos',
        ]);

        $bodega = Bodega::create([
            'nombre' => 'Bodega Central',
            'tipo' => 'estandar',
        ]);

        $response = $this->postJson('/api/productos', [
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'SKU-TEST-002',
            'producto_nombre' => 'Pastillas de Freno',
            'producto_unidad_medida' => 'unidad',
            'producto_alerta_stock_minimo' => 4,
            'producto_precio_costo' => 50000,
            'saldo_inicial' => 15,
            'bodega_id' => $bodega->bodega_id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('producto.producto_stock_actual', 15);

        $producto = Producto::where('producto_sku', 'SKU-TEST-002')->firstOrFail();
        $this->assertEquals(15, $producto->producto_stock_actual);

        // Validar que se creó la transacción de inventario tipo ingreso
        $this->assertDatabaseHas('transaccion_inventarios', [
            'producto_id' => $producto->producto_id,
            'bodega_id' => $bodega->bodega_id,
            'usuario_id' => $user->id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => 15,
            'transaccion_motivo' => 'Saldo inicial',
        ]);

        $movement = TransaccionInventario::query()
            ->where('producto_id', $producto->producto_id)
            ->where('transaccion_referencia_type', 'saldo_inicial')
            ->firstOrFail();
        $this->assertSame($producto->producto_id, $movement->transaccion_referencia_id);
        $this->assertSame($producto->producto_id, $movement->referencia?->producto_id);

        // Validar que el observer actualizó bodega_producto
        $this->assertDatabaseHas('bodega_producto', [
            'bodega_id' => $bodega->bodega_id,
            'producto_id' => $producto->producto_id,
            'cantidad' => 15,
        ]);
    }

    public function test_creates_product_with_initial_balance_using_configured_bodega(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'permisos' => ['inventario.write'],
        ]);
        Sanctum::actingAs($user);

        $categoria = Categoria::create([
            'categoria_nombre' => 'Lubricantes',
            'categoria_tipo' => 'lubricantes',
        ]);

        $bodegaPrincipal = Bodega::create([
            'nombre' => 'Bodega Principal',
            'tipo' => 'estandar',
        ]);
        config(['inventory.default_bodega_id' => $bodegaPrincipal->bodega_id]);

        $response = $this->postJson('/api/productos', [
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'SKU-TEST-003',
            'producto_nombre' => 'Aceite 15W40',
            'producto_unidad_medida' => 'galon',
            'saldo_inicial' => 8.5,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('producto.producto_stock_actual', 8.5);

        $producto = Producto::where('producto_sku', 'SKU-TEST-003')->firstOrFail();
        $this->assertEquals(8.5, $producto->producto_stock_actual);

        $this->assertDatabaseHas('transaccion_inventarios', [
            'producto_id' => $producto->producto_id,
            'bodega_id' => $bodegaPrincipal->bodega_id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => 8.5,
            'transaccion_motivo' => 'Saldo inicial',
        ]);
    }

    public function test_rejects_initial_balance_without_explicit_or_configured_warehouse(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'permisos' => ['inventario.write'],
        ]));

        $categoria = Categoria::create([
            'categoria_nombre' => 'Lubricantes',
            'categoria_tipo' => 'lubricantes',
        ]);

        $this->postJson('/api/productos', [
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'SKU-WITHOUT-WAREHOUSE',
            'producto_nombre' => 'Aceite sin bodega',
            'producto_unidad_medida' => 'galon',
            'saldo_inicial' => 8.5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('bodega_id');

        $this->assertDatabaseMissing('productos', [
            'producto_sku' => 'SKU-WITHOUT-WAREHOUSE',
        ]);
    }

    public function test_can_fetch_categories_and_bodegas_endpoints(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'permisos' => ['inventario.read'],
        ]);
        Sanctum::actingAs($user);

        Categoria::create([
            'categoria_nombre' => 'Herramientas',
            'categoria_tipo' => 'herramienta',
        ]);

        Bodega::create([
            'nombre' => 'Bodega Principal',
            'tipo' => 'estandar',
        ]);

        $resCat = $this->getJson('/api/categorias');
        $resCat->assertOk()
            ->assertJsonFragment(['nombre' => 'Herramientas', 'categoria_nombre' => 'Herramientas']);

        $resBod = $this->getJson('/api/bodegas');
        $resBod->assertOk()
            ->assertJsonFragment(['nombre' => 'Bodega Principal', 'tipo' => 'estandar']);
    }
}
