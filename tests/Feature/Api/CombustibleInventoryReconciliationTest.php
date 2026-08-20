<?php

namespace Tests\Feature\Api;

use App\Models\Bodega;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\RegistroCombustible;
use App\Models\TransaccionInventario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CombustibleInventoryReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_gasoline_to_acpm_creates_exact_reversal_and_corrected_output(): void
    {
        $scenario = $this->createFuelScenario();
        $editor = $this->actingAsAdmin();

        $response = $this->putJson("/api/combustible/{$scenario['registro']->registro_id}", [
            'tipo_combustible' => 'acpm',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ajuste_inventario', true);

        $registro = $scenario['registro']->fresh();
        $correctedOutput = TransaccionInventario::findOrFail($registro->transaccion_id);

        $this->assertSame($scenario['acpm']->producto_id, $correctedOutput->producto_id);
        $this->assertSame('salida', $correctedOutput->transaccion_tipo);
        $this->assertSame('Consumo de Combustible (Corregido)', $correctedOutput->transaccion_motivo);
        $this->assertSame($editor->id, $correctedOutput->usuario_id);
        $this->assertSame(10.0, (float) $correctedOutput->transaccion_cantidad);

        $this->assertDatabaseHas('transaccion_inventarios', [
            'producto_id' => $scenario['gasolina']->producto_id,
            'bodega_id' => $scenario['bodega']->bodega_id,
            'usuario_id' => $editor->id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => 10,
            'transaccion_motivo' => 'Reversión por corrección de tanqueo',
        ]);

        $this->assertSame(100.0, (float) $scenario['gasolina']->fresh()->producto_stock_actual);
        $this->assertSame(90.0, (float) $scenario['acpm']->fresh()->producto_stock_actual);
        $this->assertSame(100.0, $this->warehouseStock($scenario['bodega'], $scenario['gasolina']));
        $this->assertSame(90.0, $this->warehouseStock($scenario['bodega'], $scenario['acpm']));
    }

    public function test_creation_rejects_output_when_principal_warehouse_stock_is_insufficient(): void
    {
        $admin = $this->actingAsAdmin();
        $category = Categoria::create([
            'categoria_nombre' => 'Combustible',
            'categoria_tipo' => 'combustible',
        ]);
        $bodega = Bodega::create([
            'nombre' => 'Bodega Principal',
            'tipo' => 'estandar',
        ]);
        $gasolina = $this->createFuelProduct($category, 'GASOLINA', 100);
        DB::table('bodega_producto')->insert([
            'bodega_id' => $bodega->bodega_id,
            'producto_id' => $gasolina->producto_id,
            'cantidad' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/combustible', [
            'tipo_destino' => 'tercero',
            'tipo_combustible' => 'gasolina',
            'tercero_nombre' => 'Operador de prueba',
            'cantidad_galones' => 10,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Stock insuficiente de gasolina en Bodega Principal. Stock actual: 5');

        $this->assertDatabaseCount('registros_combustible', 0);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertSame(100.0, (float) $gasolina->fresh()->producto_stock_actual);
        $this->assertSame(5.0, $this->warehouseStock($bodega, $gasolina));
        $this->assertSame($admin->id, auth()->id());
    }

    public function test_creation_rejects_product_without_principal_warehouse_balance(): void
    {
        $this->actingAsAdmin();
        $category = Categoria::create([
            'categoria_nombre' => 'Combustible',
            'categoria_tipo' => 'combustible',
        ]);
        Bodega::create(['nombre' => 'Bodega Principal', 'tipo' => 'estandar']);
        $gasolina = $this->createFuelProduct($category, 'GASOLINA', 100);

        $this->postJson('/api/combustible', [
            'tipo_destino' => 'tercero',
            'tipo_combustible' => 'gasolina',
            'tercero_nombre' => 'Operador de prueba',
            'cantidad_galones' => 10,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El producto gasolina no tiene saldo configurado en Bodega Principal.');

        $this->assertDatabaseCount('registros_combustible', 0);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('bodega_producto', 0);
        $this->assertSame(100.0, (float) $gasolina->fresh()->producto_stock_actual);
    }

    public function test_changing_quantity_reverses_old_output_and_links_new_quantity(): void
    {
        $scenario = $this->createFuelScenario();
        $this->actingAsAdmin();

        $this->putJson("/api/combustible/{$scenario['registro']->registro_id}", [
            'cantidad_galones' => 15,
        ])
            ->assertOk()
            ->assertJsonPath('ajuste_inventario', true);

        $registro = $scenario['registro']->fresh();
        $correctedOutput = TransaccionInventario::findOrFail($registro->transaccion_id);

        $this->assertSame($scenario['gasolina']->producto_id, $correctedOutput->producto_id);
        $this->assertSame(15.0, (float) $correctedOutput->transaccion_cantidad);
        $this->assertSame(85.0, (float) $scenario['gasolina']->fresh()->producto_stock_actual);
        $this->assertSame(85.0, $this->warehouseStock($scenario['bodega'], $scenario['gasolina']));
        $this->assertDatabaseCount('transaccion_inventarios', 3);
    }

    public function test_non_inventory_edit_does_not_create_movements(): void
    {
        $scenario = $this->createFuelScenario();
        $this->actingAsAdmin();

        $this->putJson("/api/combustible/{$scenario['registro']->registro_id}", [
            'notas' => 'Nota administrativa corregida',
        ])
            ->assertOk()
            ->assertJsonPath('ajuste_inventario', false);

        $registro = $scenario['registro']->fresh();

        $this->assertSame('Nota administrativa corregida', $registro->notas);
        $this->assertSame($scenario['salida']->transaccion_id, $registro->transaccion_id);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame(90.0, (float) $scenario['gasolina']->fresh()->producto_stock_actual);
    }

    public function test_insufficient_stock_returns_422_and_rolls_back_entire_correction(): void
    {
        $scenario = $this->createFuelScenario(acpmStock: 5);
        $this->actingAsAdmin();

        $this->putJson("/api/combustible/{$scenario['registro']->registro_id}", [
            'tipo_combustible' => 'acpm',
        ])->assertUnprocessable();

        $registro = $scenario['registro']->fresh();

        $this->assertSame('gasolina', $registro->tipo_combustible);
        $this->assertSame($scenario['salida']->transaccion_id, $registro->transaccion_id);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame(90.0, (float) $scenario['gasolina']->fresh()->producto_stock_actual);
        $this->assertSame(5.0, (float) $scenario['acpm']->fresh()->producto_stock_actual);
        $this->assertSame(90.0, $this->warehouseStock($scenario['bodega'], $scenario['gasolina']));
        $this->assertSame(5.0, $this->warehouseStock($scenario['bodega'], $scenario['acpm']));
    }

    public function test_missing_linked_output_returns_422_without_mutating_record(): void
    {
        $scenario = $this->createFuelScenario();
        $scenario['registro']->forceFill(['transaccion_id' => null])->saveQuietly();
        $this->actingAsAdmin();

        $this->putJson("/api/combustible/{$scenario['registro']->registro_id}", [
            'tipo_combustible' => 'acpm',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaccion_id');

        $registro = $scenario['registro']->fresh();

        $this->assertSame('gasolina', $registro->tipo_combustible);
        $this->assertNull($registro->transaccion_id);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame(90.0, (float) $scenario['gasolina']->fresh()->producto_stock_actual);
        $this->assertSame(100.0, (float) $scenario['acpm']->fresh()->producto_stock_actual);
    }

    public function test_already_reversed_output_cannot_be_reversed_twice(): void
    {
        $scenario = $this->createFuelScenario();
        TransaccionInventario::create([
            'reverses_transaction_id' => $scenario['salida']->transaccion_id,
            'producto_id' => $scenario['gasolina']->producto_id,
            'bodega_id' => $scenario['bodega']->bodega_id,
            'usuario_id' => $scenario['creator']->id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => 10,
            'transaccion_motivo' => 'Reversión externa previa',
        ]);
        $this->actingAsAdmin();

        $this->putJson("/api/combustible/{$scenario['registro']->registro_id}", [
            'tipo_combustible' => 'acpm',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaccion_id');

        $this->assertSame($scenario['salida']->transaccion_id, $scenario['registro']->fresh()->transaccion_id);
        $this->assertDatabaseCount('transaccion_inventarios', 2);
        $this->assertSame(100.0, (float) $scenario['gasolina']->fresh()->producto_stock_actual);
        $this->assertSame(100.0, (float) $scenario['acpm']->fresh()->producto_stock_actual);
    }

    public function test_destination_change_requires_coherent_destination_fields(): void
    {
        $scenario = $this->createFuelScenario();
        $this->actingAsAdmin();

        $this->putJson("/api/combustible/{$scenario['registro']->registro_id}", [
            'tipo_destino' => 'vehiculo',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehiculo_id', 'empleado_id']);

        $this->assertSame('tercero', $scenario['registro']->fresh()->tipo_destino);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
    }

    public function test_retrying_same_put_is_idempotent(): void
    {
        $scenario = $this->createFuelScenario();
        $this->actingAsAdmin();
        $uri = "/api/combustible/{$scenario['registro']->registro_id}";

        $this->putJson($uri, ['tipo_combustible' => 'acpm'])
            ->assertOk()
            ->assertJsonPath('ajuste_inventario', true);

        $linkedAfterFirstPut = $scenario['registro']->fresh()->transaccion_id;

        $this->putJson($uri, ['tipo_combustible' => 'acpm'])
            ->assertOk()
            ->assertJsonPath('ajuste_inventario', false);

        $this->assertSame($linkedAfterFirstPut, $scenario['registro']->fresh()->transaccion_id);
        $this->assertDatabaseCount('transaccion_inventarios', 3);
        $this->assertSame(100.0, (float) $scenario['gasolina']->fresh()->producto_stock_actual);
        $this->assertSame(90.0, (float) $scenario['acpm']->fresh()->producto_stock_actual);
    }

    public function test_deletion_reverses_exact_linked_output_and_removes_record(): void
    {
        $scenario = $this->createFuelScenario();
        $editor = $this->actingAsAdmin();

        $this->deleteJson("/api/combustible/{$scenario['registro']->registro_id}")
            ->assertOk()
            ->assertJsonPath('inventario_revertido', true);

        $this->assertDatabaseMissing('registros_combustible', [
            'registro_id' => $scenario['registro']->registro_id,
        ]);
        $this->assertDatabaseHas('transaccion_inventarios', [
            'producto_id' => $scenario['salida']->producto_id,
            'bodega_id' => $scenario['salida']->bodega_id,
            'usuario_id' => $editor->id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => $scenario['salida']->transaccion_cantidad,
            'transaccion_motivo' => 'Reversión por eliminación de tanqueo',
        ]);
        $this->assertDatabaseCount('transaccion_inventarios', 2);
        $this->assertSame(100.0, (float) $scenario['gasolina']->fresh()->producto_stock_actual);
        $this->assertSame(100.0, $this->warehouseStock($scenario['bodega'], $scenario['gasolina']));
    }

    public function test_reconciliation_command_is_dry_run_by_default(): void
    {
        $scenario = $this->createFuelScenario();
        $scenario['registro']->forceFill(['tipo_combustible' => 'acpm'])->saveQuietly();

        $this->artisan('combustible:conciliar-inventario')
            ->expectsOutputToContain('Se detectaron 1 inconsistencia(s)')
            ->expectsOutputToContain('Modo auditoría: no se modificó ningún dato')
            ->assertSuccessful();

        $this->assertSame($scenario['salida']->transaccion_id, $scenario['registro']->fresh()->transaccion_id);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame(90.0, (float) $scenario['gasolina']->fresh()->producto_stock_actual);
        $this->assertSame(100.0, (float) $scenario['acpm']->fresh()->producto_stock_actual);
    }

    public function test_reconciliation_command_applies_atomically_and_is_idempotent(): void
    {
        $scenario = $this->createFuelScenario();
        $scenario['registro']->forceFill(['tipo_combustible' => 'acpm'])->saveQuietly();
        $auditor = User::factory()->create(['role' => 'admin']);

        $this->artisan('combustible:conciliar-inventario', [
            '--apply' => true,
            '--force' => true,
            '--usuario' => $auditor->id,
        ])
            ->expectsOutputToContain('Conciliación completada: 1 registro(s) corregido(s)')
            ->assertSuccessful();

        $linkedTransactionId = $scenario['registro']->fresh()->transaccion_id;
        $this->assertNotSame($scenario['salida']->transaccion_id, $linkedTransactionId);
        $this->assertDatabaseCount('transaccion_inventarios', 3);
        $this->assertSame(100.0, (float) $scenario['gasolina']->fresh()->producto_stock_actual);
        $this->assertSame(90.0, (float) $scenario['acpm']->fresh()->producto_stock_actual);

        $this->artisan('combustible:conciliar-inventario', [
            '--apply' => true,
            '--force' => true,
            '--usuario' => $auditor->id,
        ])
            ->expectsOutput('No se encontraron inconsistencias entre tanqueos y salidas de inventario.')
            ->assertSuccessful();

        $this->assertSame($linkedTransactionId, $scenario['registro']->fresh()->transaccion_id);
        $this->assertDatabaseCount('transaccion_inventarios', 3);
    }

    public function test_reconciliation_command_rejects_invalid_record_filter_without_falling_back_to_all(): void
    {
        $scenario = $this->createFuelScenario();
        $scenario['registro']->forceFill(['tipo_combustible' => 'acpm'])->saveQuietly();
        $auditor = User::factory()->create(['role' => 'admin']);

        $this->artisan('combustible:conciliar-inventario', [
            '--registro' => ['abc'],
            '--apply' => true,
            '--force' => true,
            '--usuario' => $auditor->id,
        ])
            ->expectsOutputToContain('Cada valor de --registro debe ser un ID entero positivo')
            ->assertFailed();

        $this->assertSame($scenario['salida']->transaccion_id, $scenario['registro']->fresh()->transaccion_id);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
    }

    public function test_reconciliation_command_blocks_negative_global_or_warehouse_projection(): void
    {
        $scenario = $this->createFuelScenario(acpmStock: 5);
        $scenario['registro']->forceFill(['tipo_combustible' => 'acpm'])->saveQuietly();
        $auditor = User::factory()->create(['role' => 'admin']);

        $this->artisan('combustible:conciliar-inventario', [
            '--apply' => true,
            '--force' => true,
            '--usuario' => $auditor->id,
        ])
            ->expectsOutputToContain('La conciliación fue bloqueada porque produciría un saldo negativo')
            ->assertFailed();

        $this->assertSame($scenario['salida']->transaccion_id, $scenario['registro']->fresh()->transaccion_id);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame(90.0, (float) $scenario['gasolina']->fresh()->producto_stock_actual);
        $this->assertSame(5.0, (float) $scenario['acpm']->fresh()->producto_stock_actual);
        $this->assertSame(90.0, $this->warehouseStock($scenario['bodega'], $scenario['gasolina']));
        $this->assertSame(5.0, $this->warehouseStock($scenario['bodega'], $scenario['acpm']));
    }

    /**
     * @return array{creator: User, bodega: Bodega, gasolina: Producto, acpm: Producto, salida: TransaccionInventario, registro: RegistroCombustible}
     */
    private function createFuelScenario(float $gasolineStock = 100, float $acpmStock = 100): array
    {
        $creator = User::factory()->create(['role' => 'admin']);
        $category = Categoria::create([
            'categoria_nombre' => 'Combustible',
            'categoria_tipo' => 'combustible',
        ]);
        $bodega = Bodega::create([
            'nombre' => 'Bodega Principal',
            'tipo' => 'estandar',
        ]);
        $gasolina = $this->createFuelProduct($category, 'GASOLINA', $gasolineStock);
        $acpm = $this->createFuelProduct($category, 'ACPM', $acpmStock);

        foreach ([[$gasolina, $gasolineStock], [$acpm, $acpmStock]] as [$product, $stock]) {
            DB::table('bodega_producto')->insert([
                'bodega_id' => $bodega->bodega_id,
                'producto_id' => $product->producto_id,
                'cantidad' => $stock,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $salida = TransaccionInventario::create([
            'producto_id' => $gasolina->producto_id,
            'bodega_id' => $bodega->bodega_id,
            'usuario_id' => $creator->id,
            'transaccion_tipo' => 'salida',
            'transaccion_cantidad' => 10,
            'transaccion_motivo' => 'Consumo de Combustible (Interno)',
            'transaccion_referencia_type' => 'Tercero',
            'transaccion_referencia_id' => null,
            'transaccion_notas' => 'Tanqueo inicial de prueba',
        ]);

        $registro = RegistroCombustible::create([
            'transaccion_id' => $salida->transaccion_id,
            'vehiculo_id' => null,
            'empleado_id' => null,
            'tercero_nombre' => 'Operador de prueba',
            'tipo_destino' => 'tercero',
            'tipo_combustible' => 'gasolina',
            'usuario_id' => $creator->id,
            'fecha' => now(),
            'cantidad_galones' => 10,
            'valor_total' => 100000,
            'notas' => 'Tanqueo inicial',
        ]);

        return compact('creator', 'bodega', 'gasolina', 'acpm', 'salida', 'registro');
    }

    private function createFuelProduct(Categoria $category, string $name, float $stock): Producto
    {
        return Producto::create([
            'categoria_id' => $category->categoria_id,
            'producto_sku' => "FUEL-{$name}",
            'producto_nombre' => $name,
            'producto_unidad_medida' => 'galón',
            'producto_stock_actual' => $stock,
            'producto_alerta_stock_minimo' => 1,
            'producto_precio_costo' => 1000,
        ]);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function warehouseStock(Bodega $bodega, Producto $producto): float
    {
        return (float) DB::table('bodega_producto')
            ->where('bodega_id', $bodega->bodega_id)
            ->where('producto_id', $producto->producto_id)
            ->value('cantidad');
    }
}
