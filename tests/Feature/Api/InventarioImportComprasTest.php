<?php

namespace Tests\Feature\Api;

use App\Models\Bodega;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use App\Models\User;
use App\Services\Inventory\ComprasImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

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

    public function test_preview_is_read_only_and_returns_one_confirmation_key(): void
    {
        $response = $this->preview($this->csvContent);

        $response->assertOk()
            ->assertJsonPath('summary.valid_skus', 2)
            ->assertJsonPath('summary.existing_products_count', 1)
            ->assertJsonPath('summary.new_products_count', 1)
            ->assertJsonPath('requires_confirmation', true);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('idempotency_key'));
        $this->assertNotSame(
            $response->json('idempotency_key'),
            $this->preview($this->csvContent)->json('idempotency_key'),
        );
        $this->assertDatabaseCount('categorias', 1);
        $this->assertDatabaseMissing('productos', ['producto_sku' => 'NEW-1']);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('inventario_importaciones', 0);
    }

    public function test_preview_does_not_create_a_category_declared_only_in_the_file(): void
    {
        $content = implode("\n", [
            'Código,Nombre,Referencia fábrica,Categoría,Saldo cantidades',
            'NEW-CAT,Producto Nuevo,REF-C,Categoría futura,4',
        ]);

        $this->preview($content)
            ->assertOk()
            ->assertJsonPath('new.0.categoria_texto', 'Categoría futura');

        $this->assertDatabaseMissing('categorias', ['categoria_nombre' => 'Categoría futura']);
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

    public function test_apply_creates_products_and_ledger_movements_in_explicit_warehouse(): void
    {
        $key = $this->preview($this->csvContent)->json('idempotency_key');

        $response = $this->apply($this->csvContent, $key);

        $response->assertOk()
            ->assertJsonPath('created_products', 1)
            ->assertJsonPath('updated_products', 1)
            ->assertJsonPath('movements_created', 2)
            ->assertJsonPath('idempotent_replay', false);

        $this->assertEquals(8.0, (float) Producto::where('producto_sku', 'EXIST-1')->value('producto_stock_actual'));
        $this->assertEquals(10.0, (float) Producto::where('producto_sku', 'NEW-1')->value('producto_stock_actual'));
        $this->assertDatabaseCount('transaccion_inventarios', 2);
        $this->assertDatabaseHas('transaccion_inventarios', [
            'bodega_id' => $this->bodega->bodega_id,
            'transaccion_referencia_type' => 'compra_import',
        ]);
    }

    public function test_retry_with_same_confirmation_key_does_not_duplicate_stock(): void
    {
        $key = $this->preview($this->csvContent)->json('idempotency_key');

        $this->apply($this->csvContent, $key)->assertOk();
        $this->apply($this->csvContent, $key)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);

        $this->assertEquals(8.0, (float) Producto::where('producto_sku', 'EXIST-1')->value('producto_stock_actual'));
        $this->assertEquals(10.0, (float) Producto::where('producto_sku', 'NEW-1')->value('producto_stock_actual'));
        $this->assertDatabaseCount('transaccion_inventarios', 2);
        $this->assertDatabaseCount('inventario_importaciones', 1);
    }

    public function test_apply_rejects_a_missing_or_stale_confirmation_key(): void
    {
        $this->postJson('/api/inventario/import-compras', [
            'file' => UploadedFile::fake()->createWithContent('compras.csv', $this->csvContent),
            'dry_run' => 0,
            'bodega_id' => $this->bodega->bodega_id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseMissing('productos', ['producto_sku' => 'NEW-1']);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_same_key_with_different_file_or_warehouse_is_a_conflict(): void
    {
        $key = $this->preview($this->csvContent)->json('idempotency_key');
        $this->apply($this->csvContent, $key)->assertOk();
        $changedContent = str_replace(',10', ',11', $this->csvContent);

        $this->apply($changedContent, $key)
            ->assertStatus(409)
            ->assertJsonPath('message', 'La clave de idempotencia ya fue usada con otro archivo, bodega u opciones.');

        $otherWarehouse = Bodega::create([
            'nombre' => 'Bodega Secundaria',
            'tipo' => 'estandar',
        ]);
        $this->postJson('/api/inventario/import-compras', [
            'file' => UploadedFile::fake()->createWithContent('compras.csv', $this->csvContent),
            'dry_run' => 0,
            'bodega_id' => $otherWarehouse->bodega_id,
            'idempotency_key' => $key,
        ])->assertStatus(409);

        $this->assertEquals(8.0, (float) Producto::where('producto_sku', 'EXIST-1')->value('producto_stock_actual'));
        $this->assertEquals(10.0, (float) Producto::where('producto_sku', 'NEW-1')->value('producto_stock_actual'));
        $this->assertDatabaseCount('transaccion_inventarios', 2);
        $this->assertDatabaseCount('inventario_importaciones', 1);
    }

    public function test_new_preview_key_allows_a_distinct_legitimate_import_with_identical_file(): void
    {
        $firstKey = $this->preview($this->csvContent)->json('idempotency_key');
        $this->apply($this->csvContent, $firstKey)->assertOk();

        $secondKey = $this->preview($this->csvContent)->json('idempotency_key');
        $this->assertNotSame($firstKey, $secondKey);
        $this->apply($this->csvContent, $secondKey)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', false);

        $this->assertEquals(11.0, (float) Producto::where('producto_sku', 'EXIST-1')->value('producto_stock_actual'));
        $this->assertEquals(20.0, (float) Producto::where('producto_sku', 'NEW-1')->value('producto_stock_actual'));
        $this->assertDatabaseCount('transaccion_inventarios', 4);
        $this->assertDatabaseCount('inventario_importaciones', 2);
    }

    public function test_import_requires_an_explicit_or_configured_warehouse(): void
    {
        $this->postJson('/api/inventario/import-compras', [
            'file' => UploadedFile::fake()->createWithContent('compras.csv', $this->csvContent),
            'dry_run' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('bodega_id');
    }

    public function test_legacy_product_import_endpoint_is_fail_closed(): void
    {
        $this->postJson('/api/productos/import', [
            'file' => UploadedFile::fake()->createWithContent('legacy.csv', $this->csvContent),
        ])->assertStatus(410)
            ->assertJsonPath('replacement', '/api/inventario/import-compras');

        $this->assertDatabaseMissing('productos', ['producto_sku' => 'NEW-1']);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('inventario_importaciones', 0);
    }

    public function test_import_rejects_files_over_the_configured_size_and_row_limits(): void
    {
        config([
            'inventory.imports.max_file_kilobytes' => 1,
            'inventory.imports.max_rows' => 1,
        ]);

        $this->postJson('/api/inventario/import-compras', [
            'file' => UploadedFile::fake()->create('large.csv', 2, 'text/csv'),
            'dry_run' => 1,
            'bodega_id' => $this->bodega->bodega_id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $twoRows = implode("\n", [
            'Código,Nombre,Referencia fábrica,Categoría,Saldo cantidades',
            'LIMIT-1,Producto uno,REF-1,Insumos,1',
            'LIMIT-2,Producto dos,REF-2,Insumos,1',
        ]);
        $this->preview($twoRows)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El archivo supera el límite de 1 filas.');
    }

    public function test_import_rejects_legacy_xls_and_never_calculates_xlsx_formulas(): void
    {
        $this->postJson('/api/inventario/import-compras', [
            'file' => UploadedFile::fake()->create('legacy.xls', 1, 'application/vnd.ms-excel'),
            'dry_run' => 1,
            'bodega_id' => $this->bodega->bodega_id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('file');

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
            ])->assertUnprocessable()
                ->assertJsonPath('message', 'El archivo contiene fórmulas o valores ejecutables no permitidos.');
        } finally {
            $spreadsheet->disconnectWorksheets();
            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }

        $this->assertDatabaseMissing('productos', ['producto_sku' => 'FORMULA-1']);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
    }

    public function test_apply_is_fail_closed_and_rolls_back_every_change(): void
    {
        $content = implode("\n", [
            'Código,Nombre,Referencia fábrica,Categoría,Saldo cantidades',
            'ROLLBACK-1,Producto rollback,REF-R,Insumos,6',
        ]);
        $key = $this->preview($content)->json('idempotency_key');

        TransaccionInventario::creating(static function (): void {
            throw new RuntimeException('Falla simulada del ledger');
        });

        $this->apply($content, $key)
            ->assertStatus(500)
            ->assertJsonPath('message', 'La importación falló y no se aplicaron cambios.');

        $this->assertDatabaseMissing('productos', ['producto_sku' => 'ROLLBACK-1']);
        $this->assertDatabaseCount('transaccion_inventarios', 0);
        $this->assertDatabaseCount('inventario_importaciones', 0);
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
