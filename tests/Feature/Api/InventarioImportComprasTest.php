<?php

namespace Tests\Feature\Api;

use App\Models\Bodega;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;
use App\Services\Inventory\ComprasImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

// U9 — corte definitivo del CSV de compras (plan sección 13): el endpoint
// /api/inventario/import-compras responde 410 Gone de forma incondicional y
// jamás muta stock. Esta suite (excepción autorizada a "no tocar tests
// ajenos") fija ese contrato: estado 410 + puntero de reemplazo + cero
// efecto en catálogo, ledger e historial de importaciones.
class InventarioImportComprasTest extends TestCase
{
    use RefreshDatabase;

    private string $csvContent;

    private Categoria $categoria;

    private Bodega $bodega;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->categoria = Categoria::create([
            'categoria_nombre' => 'Insumos',
            'categoria_tipo' => 'insumo',
        ]);
        $this->bodega = Bodega::create([
            'nombre' => 'Bodega Principal',
            'tipo' => 'estandar',
        ]);
        Producto::create([
            'categoria_id' => $this->categoria->categoria_id,
            'producto_sku' => 'EXIST-1',
            'producto_nombre' => 'Producto Existente',
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => 5,
            'producto_alerta_stock_minimo' => 1,
            'producto_precio_costo' => 1000,
        ]);

        $this->csvContent = implode("\n", [
            'Código,Nombre,Referencia fábrica,Categoría,Saldo cantidades',
            'EXIST-1,Producto Existente,REF-A,Insumos,3',
            'NEW-1,Producto Nuevo,REF-B,Insumos,10',
        ]);
    }

    public function test_preview_is_gone_and_mutates_nothing(): void
    {
        $this->preview($this->csvContent)
            ->assertStatus(410)
            ->assertJsonPath('replacement', '/api/web/inventory-orders');

        $this->assertDatabaseMissing('productos', ['producto_sku' => 'NEW-1']);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('inventario_importaciones', 0);
    }

    public function test_preview_catalog_queries_remain_constant_as_rows_grow(): void
    {
        $rows = [];
        for ($index = 0; $index < 30; $index++) {
            Producto::create([
                'categoria_id' => $this->categoria->categoria_id,
                'producto_sku' => "BULK-{$index}",
                'producto_nombre' => "Producto {$index}",
                'producto_unidad_medida' => 'unidad',
                'producto_stock_actual' => 0,
            ]);
            $rows[] = [
                'line' => $index + 2,
                'sku' => "BULK-{$index}",
                'nombre' => "Producto {$index}",
                'referencia' => null,
                'categoria_texto' => 'Insumos',
                'cantidad' => 1,
            ];
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ComprasImportService::class)->buildPreview($rows, [
            'categoria_id' => $this->categoria->categoria_id,
        ]);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $queryCount);
    }

    public function test_apply_is_gone_and_mutates_nothing(): void
    {
        $this->apply($this->csvContent, str_repeat('a', 64))
            ->assertStatus(410)
            ->assertJsonPath('replacement', '/api/web/inventory-orders');

        $this->assertEquals(5.0, (float) Producto::where('producto_sku', 'EXIST-1')->value('producto_stock_actual'));
        $this->assertDatabaseMissing('productos', ['producto_sku' => 'NEW-1']);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('inventario_importaciones', 0);
    }

    public function test_retry_with_same_confirmation_key_is_still_gone(): void
    {
        $key = str_repeat('b', 64);

        $this->apply($this->csvContent, $key)->assertStatus(410);
        $this->apply($this->csvContent, $key)
            ->assertStatus(410)
            ->assertJsonPath('replacement', '/api/web/inventory-orders');

        $this->assertEquals(5.0, (float) Producto::where('producto_sku', 'EXIST-1')->value('producto_stock_actual'));
        $this->assertDatabaseMissing('productos', ['producto_sku' => 'NEW-1']);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('inventario_importaciones', 0);
    }

    public function test_apply_without_key_is_gone_before_validation(): void
    {
        $this->postJson('/api/inventario/import-compras', [
            'file' => UploadedFile::fake()->createWithContent('compras.csv', $this->csvContent),
            'dry_run' => 0,
            'bodega_id' => $this->bodega->bodega_id,
        ])->assertStatus(410)
            ->assertJsonPath('replacement', '/api/web/inventory-orders');

        $this->assertDatabaseMissing('productos', ['producto_sku' => 'NEW-1']);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_same_key_with_different_file_or_warehouse_is_still_gone(): void
    {
        $key = str_repeat('c', 64);
        $this->apply($this->csvContent, $key)->assertStatus(410);
        $changedContent = str_replace(',10', ',11', $this->csvContent);

        $this->apply($changedContent, $key)
            ->assertStatus(410)
            ->assertJsonPath('replacement', '/api/web/inventory-orders');

        $otherWarehouse = Bodega::create([
            'nombre' => 'Bodega Secundaria',
            'tipo' => 'estandar',
        ]);
        $this->postJson('/api/inventario/import-compras', [
            'file' => UploadedFile::fake()->createWithContent('compras.csv', $this->csvContent),
            'dry_run' => 0,
            'bodega_id' => $otherWarehouse->bodega_id,
            'idempotency_key' => $key,
        ])->assertStatus(410);

        $this->assertEquals(5.0, (float) Producto::where('producto_sku', 'EXIST-1')->value('producto_stock_actual'));
        $this->assertDatabaseMissing('productos', ['producto_sku' => 'NEW-1']);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('inventario_importaciones', 0);
    }

    public function test_repeated_calls_never_accumulate_stock(): void
    {
        $firstKey = str_repeat('d', 64);
        $this->apply($this->csvContent, $firstKey)->assertStatus(410);

        $secondKey = str_repeat('e', 64);
        $this->apply($this->csvContent, $secondKey)
            ->assertStatus(410)
            ->assertJsonPath('replacement', '/api/web/inventory-orders');

        $this->assertEquals(5.0, (float) Producto::where('producto_sku', 'EXIST-1')->value('producto_stock_actual'));
        $this->assertDatabaseMissing('productos', ['producto_sku' => 'NEW-1']);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('inventario_importaciones', 0);
    }

    public function test_import_is_gone_even_without_warehouse(): void
    {
        // El 410 precede a cualquier validación: ya no depende de la bodega
        // por defecto del entorno (antes rojo según plan sección 3).
        $this->postJson('/api/inventario/import-compras', [
            'file' => UploadedFile::fake()->createWithContent('compras.csv', $this->csvContent),
            'dry_run' => 1,
        ])->assertStatus(410)
            ->assertJsonPath('replacement', '/api/web/inventory-orders');
    }

    public function test_legacy_product_import_endpoint_is_fail_closed(): void
    {
        $this->postJson('/api/productos/import', [
            'file' => UploadedFile::fake()->createWithContent('legacy.csv', $this->csvContent),
        ])->assertStatus(410)
            ->assertJsonPath('replacement', '/api/web/inventory-orders');

        $this->assertDatabaseMissing('productos', ['producto_sku' => 'NEW-1']);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('inventario_importaciones', 0);
    }

    public function test_import_is_gone_regardless_of_file_size_and_row_limits(): void
    {
        config([
            'inventory.imports.max_file_kilobytes' => 1,
            'inventory.imports.max_rows' => 1,
        ]);

        $this->postJson('/api/inventario/import-compras', [
            'file' => UploadedFile::fake()->create('large.csv', 2, 'text/csv'),
            'dry_run' => 1,
            'bodega_id' => $this->bodega->bodega_id,
        ])->assertStatus(410)
            ->assertJsonPath('replacement', '/api/web/inventory-orders');

        $twoRows = implode("\n", [
            'Código,Nombre,Referencia fábrica,Categoría,Saldo cantidades',
            'LIMIT-1,Producto uno,REF-1,Insumos,1',
            'LIMIT-2,Producto dos,REF-2,Insumos,1',
        ]);
        $this->preview($twoRows)->assertStatus(410);

        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('inventario_importaciones', 0);
    }

    public function test_import_is_gone_regardless_of_file_type(): void
    {
        $this->postJson('/api/inventario/import-compras', [
            'file' => UploadedFile::fake()->create('legacy.xls', 1, 'application/vnd.ms-excel'),
            'dry_run' => 1,
            'bodega_id' => $this->bodega->bodega_id,
        ])->assertStatus(410);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Código', 'Nombre', 'Referencia fábrica', 'Categoría', 'Saldo cantidades'],
            ['FORMULA-1', 'Producto fórmula', 'REF-F', 'Insumos', '=2+3'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'semanur-xlsx-');
        $this->assertNotFalse($path);

        try {
            (new Xlsx($spreadsheet))->save($path);
            $file = new UploadedFile(
                $path,
                'formula.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            );

            $this->postJson('/api/inventario/import-compras', [
                'file' => $file,
                'dry_run' => 1,
                'bodega_id' => $this->bodega->bodega_id,
            ])->assertStatus(410);
        } finally {
            $spreadsheet->disconnectWorksheets();
            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }

        $this->assertDatabaseMissing('productos', ['producto_sku' => 'FORMULA-1']);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_cut_endpoint_carries_guidance_to_the_new_flow(): void
    {
        $this->preview($this->csvContent)
            ->assertStatus(410)
            ->assertJsonStructure(['message', 'replacement', 'guidance'])
            ->assertJsonPath('replacement', '/api/web/inventory-orders');
    }

    private function preview(string $content)
    {
        return $this->postJson('/api/inventario/import-compras', [
            'file' => UploadedFile::fake()->createWithContent('compras.csv', $content),
            'dry_run' => 1,
            'bodega_id' => $this->bodega->bodega_id,
        ]);
    }

    private function apply(string $content, string $key)
    {
        return $this->postJson('/api/inventario/import-compras', [
            'file' => UploadedFile::fake()->createWithContent('compras.csv', $content),
            'dry_run' => 0,
            'bodega_id' => $this->bodega->bodega_id,
            'idempotency_key' => $key,
        ]);
    }
}
