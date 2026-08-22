<?php

namespace Tests\Feature\Console;

use App\Models\Categoria;
use App\Models\Producto;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportInventoryExcelTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_command_fails_closed_without_touching_inventory(): void
    {
        $categoria = Categoria::create([
            'categoria_nombre' => 'Repuestos',
            'categoria_tipo' => 'repuesto',
        ]);
        Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'KEEP-1',
            'producto_nombre' => 'Producto protegido',
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => 12,
        ]);
        $path = storage_path('framework/testing/legacy-inventory.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, 'not-used');

        try {
            $this->artisan('inventory:import-excel', ['filepath' => $path])
                ->expectsOutput('Este comando fue retirado porque eliminaba productos y omitía el ledger de inventario.')
                ->assertExitCode(Command::FAILURE);
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseHas('productos', [
            'producto_sku' => 'KEEP-1',
            'producto_stock_actual' => 12,
        ]);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }
}
