<?php

namespace Tests\Feature\Console;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditarCombustibleLedgerPorIngresoTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reconstructs_balance_by_income_period_without_mutating_data(): void
    {
        $category = Categoria::create([
            'categoria_nombre' => 'Combustible',
            'categoria_tipo' => 'combustible',
        ]);
        $gasoline = Producto::create([
            'categoria_id' => $category->categoria_id,
            'producto_sku' => 'FUEL-GASOLINA',
            'producto_nombre' => 'GASOLINA',
            'producto_unidad_medida' => 'galón',
            'producto_stock_actual' => 100,
            'producto_alerta_stock_minimo' => 1,
            'producto_precio_costo' => 1000,
        ]);

        $this->createMovement($gasoline, 'salida', 20, '2026-08-01 08:00:00');
        $firstIncome = $this->createMovement($gasoline, 'ingreso', 500, '2026-08-02 08:00:00');
        $this->createMovement($gasoline, 'salida', 200, '2026-08-03 08:00:00');
        $this->createMovement($gasoline, 'ingreso', 100, '2026-08-04 08:00:00');
        $this->createMovement($gasoline, 'salida', 50, '2026-08-05 08:00:00');

        $this->artisan('combustible:auditar-ledger-por-ingreso', [
            '--producto' => ['gasolina'],
        ])
            ->expectsOutputToContain('Saldo inicial implícito')
            ->expectsOutputToContain('#'.$firstIncome->transaccion_id)
            ->expectsOutputToContain('Auditoría completada en modo lectura')
            ->assertSuccessful();

        $this->assertSame(430.0, (float) $gasoline->fresh()->producto_stock_actual);
        $this->assertDatabaseCount('transaccion_inventarios', 5);
    }

    private function createMovement(
        Producto $product,
        string $type,
        float $quantity,
        string $createdAt,
    ): TransaccionInventario {
        $movement = TransaccionInventario::create([
            'producto_id' => $product->producto_id,
            'transaccion_tipo' => $type,
            'transaccion_cantidad' => $quantity,
            'transaccion_motivo' => 'Movimiento de prueba',
        ]);
        $movement->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $movement;
    }
}
