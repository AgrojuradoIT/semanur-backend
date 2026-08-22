<?php

namespace Tests\Feature\Api;

use App\Models\Bodega;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryProducerIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Bodega $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->actor);
        $this->warehouse = Bodega::create([
            'nombre' => 'Bodega Principal',
            'tipo' => 'estandar',
        ]);
        config(['inventory.default_bodega_id' => $this->warehouse->bodega_id]);
    }

    public function test_fuel_replay_returns_original_record_and_does_not_repeat_stock_output(): void
    {
        $fuel = $this->product('FUEL-IDEM', 'Gasolina', 'combustible', 100);
        $operationId = (string) Str::uuid();
        $payload = [
            'client_operation_id' => $operationId,
            'tipo_destino' => 'tercero',
            'tipo_combustible' => 'gasolina',
            'tercero_nombre' => 'Operador idempotente',
            'cantidad_galones' => 10,
            'valor_total' => 100,
        ];

        $first = $this->postJson('/api/combustible', $payload)
            ->assertCreated()
            ->assertJsonPath('idempotent_replay', false)
            ->assertJsonPath('idempotency_protected', true);

        $replay = $this->postJson('/api/combustible', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);

        $this->assertSame($first->json('registro.registro_id'), $replay->json('registro.registro_id'));
        $this->assertDatabaseCount('registros_combustible', 1);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame(90.0, (float) $fuel->fresh()->producto_stock_actual);

        $this->postJson('/api/combustible', [
            ...$payload,
            'cantidad_galones' => 11,
        ])->assertStatus(409);

        $this->assertDatabaseCount('registros_combustible', 1);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame(90.0, (float) $fuel->fresh()->producto_stock_actual);
    }

    public function test_work_order_replay_returns_original_order_and_does_not_repeat_parts_output(): void
    {
        $part = $this->product('PART-IDEM', 'Filtro idempotente', 'repuesto', 20);
        $vehicle = Vehiculo::create([
            'placa' => 'IDM101',
            'tipo' => 'tractor',
            'marca' => 'CAT',
            'modelo' => 'D6',
        ]);
        $operationId = (string) Str::uuid();
        $payload = [
            'client_operation_id' => $operationId,
            'vehiculo_id' => $vehicle->vehiculo_id,
            'prioridad' => 'Media',
            'descripcion' => 'Orden con retry seguro',
            'repuestos' => [[
                'producto_id' => $part->producto_id,
                'cantidad' => 2,
            ]],
        ];

        $first = $this->postJson('/api/ordenes-trabajo', $payload)
            ->assertCreated()
            ->assertJsonPath('idempotent_replay', false)
            ->assertJsonPath('idempotency_protected', true);

        $replay = $this->postJson('/api/ordenes-trabajo', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);

        $this->assertSame(
            $first->json('orden.orden_trabajo_id'),
            $replay->json('orden.orden_trabajo_id'),
        );
        $this->assertDatabaseCount('orden_trabajos', 1);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame(18.0, (float) $part->fresh()->producto_stock_actual);

        $this->postJson('/api/ordenes-trabajo', [
            ...$payload,
            'descripcion' => 'Payload distinto',
        ])->assertStatus(409);

        $this->assertDatabaseCount('orden_trabajos', 1);
        $this->assertDatabaseCount('transaccion_inventarios', 1);
        $this->assertSame(18.0, (float) $part->fresh()->producto_stock_actual);
    }

    public function test_enforcement_flag_rejects_legacy_writes_without_operation_id(): void
    {
        config(['inventory.require_client_operation_id' => true]);

        $this->postJson('/api/combustible', [
            'tipo_destino' => 'tercero',
            'tipo_combustible' => 'gasolina',
            'tercero_nombre' => 'Operador',
            'cantidad_galones' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('client_operation_id');

        $vehicle = Vehiculo::create([
            'placa' => 'IDM102',
            'tipo' => 'tractor',
            'marca' => 'CAT',
            'modelo' => 'D6',
        ]);
        $this->postJson('/api/ordenes-trabajo', [
            'vehiculo_id' => $vehicle->vehiculo_id,
            'prioridad' => 'Baja',
            'descripcion' => 'Debe requerir UUID',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('client_operation_id');
    }

    public function test_fuel_write_never_guesses_the_first_warehouse(): void
    {
        config(['inventory.default_bodega_id' => null]);
        $fuel = $this->product('FUEL-NO-FALLBACK', 'Gasolina', 'combustible', 10);
        Bodega::create(['nombre' => 'Otra bodega', 'tipo' => 'estandar']);

        $this->postJson('/api/combustible', [
            'tipo_destino' => 'tercero',
            'tipo_combustible' => 'gasolina',
            'tercero_nombre' => 'Operador',
            'cantidad_galones' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('bodega_id');

        $this->assertDatabaseCount('registros_combustible', 0);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertSame(10.0, (float) $fuel->fresh()->producto_stock_actual);
    }

    private function product(string $sku, string $name, string $categoryType, float $stock): Producto
    {
        $category = Categoria::create([
            'categoria_nombre' => "Category {$sku}",
            'categoria_tipo' => $categoryType,
        ]);
        $product = Producto::create([
            'categoria_id' => $category->categoria_id,
            'producto_sku' => $sku,
            'producto_nombre' => $name,
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => $stock,
            'producto_alerta_stock_minimo' => 1,
            'producto_precio_costo' => 1,
        ]);
        DB::table('bodega_producto')->insert([
            'bodega_id' => $this->warehouse->bodega_id,
            'producto_id' => $product->producto_id,
            'cantidad' => $stock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $product;
    }
}
