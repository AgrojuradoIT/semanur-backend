<?php

namespace Tests\Feature\InventoryOrders;

use App\Filament\Resources\TransaccionInventarioResource;
use App\Models\Bodega;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// U9 — hardening de atajos de ingreso (plan sección 13, C12; D4 documenta que
// NO hay exclusividad universal): el vínculo ledger<->pedido solo lo escribe
// PedidoRecepcionService vía forceCreate; ningún cliente de /movimientos,
// saldo inicial o Filament puede fabricar una recepción.
class PedidoLedgerIngressHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Producto $producto;

    private Bodega $bodega;

    protected function setUp(): void
    {
        parent::setUp();

        $categoria = Categoria::create([
            'categoria_nombre' => 'Insumos U9',
            'categoria_tipo' => 'insumo',
        ]);
        $this->bodega = Bodega::create(['nombre' => 'Bodega U9', 'tipo' => 'estandar']);
        $this->producto = Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'U9-HARD-1',
            'producto_nombre' => 'Producto hardening U9',
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => 0,
            'producto_alerta_stock_minimo' => 1,
            'producto_precio_costo' => 100,
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_pedido_linea_id_is_not_mass_assignable(): void
    {
        $this->assertNotContains('pedido_linea_id', (new TransaccionInventario)->getFillable());

        $movimiento = TransaccionInventario::create([
            'producto_id' => $this->producto->producto_id,
            'bodega_id' => $this->bodega->bodega_id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => 2,
            'pedido_linea_id' => 999999,
        ]);

        $this->assertNull($movimiento->getAttribute('pedido_linea_id'));
        $this->assertNull($movimiento->fresh()->getAttribute('pedido_linea_id'));
    }

    #[DataProvider('reservedReferenceProvider')]
    public function test_movimientos_rejects_reserved_references(?string $type, array $extra = []): void
    {
        $payload = array_merge([
            'client_operation_id' => (string) Str::uuid(),
            'producto_id' => $this->producto->producto_id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => 1,
            'bodega_id' => $this->bodega->bodega_id,
            'transaccion_referencia_type' => $type,
        ], $extra);

        $this->postJson('/api/movimientos', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($extra !== [] ? array_keys($extra) : ['transaccion_referencia_type']);

        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    /** @return array<string, array{0: string|null, 1?: array<string, mixed>}> */
    public static function reservedReferenceProvider(): array
    {
        return [
            'pedido_inventario spoof' => ['pedido_inventario'],
            'pedido_inventario_linea spoof' => ['pedido_inventario_linea'],
            'compra_import retirado' => ['compra_import'],
            'saldo_inicial reservado' => ['saldo_inicial'],
            'pedido_linea_id prohibido' => [null, ['pedido_linea_id' => 123]],
        ];
    }

    public function test_movimientos_still_accepts_legitimate_references(): void
    {
        $this->postJson('/api/movimientos', [
            'client_operation_id' => (string) Str::uuid(),
            'producto_id' => $this->producto->producto_id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => 3,
            'bodega_id' => $this->bodega->bodega_id,
            'transaccion_motivo' => 'Ajuste operativo auditado',
            'transaccion_referencia_type' => 'AjusteOperativo',
        ])->assertCreated();

        $this->assertDatabaseCount('transaccion_inventarios', 1);
    }

    public function test_filament_ledger_resource_stays_read_only(): void
    {
        $this->assertFalse(TransaccionInventarioResource::canCreate());
        $this->assertFalse(TransaccionInventarioResource::canDeleteAny());

        $movimiento = TransaccionInventario::create([
            'producto_id' => $this->producto->producto_id,
            'bodega_id' => $this->bodega->bodega_id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => 1,
        ]);

        $this->assertFalse(TransaccionInventarioResource::canEdit($movimiento));
        $this->assertFalse(TransaccionInventarioResource::canDelete($movimiento));
    }
}
