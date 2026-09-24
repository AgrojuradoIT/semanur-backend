<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\TransicionInvalidaException;
use App\Models\Categoria;
use App\Models\PedidoEvento;
use App\Models\PedidoInventarioLinea;
use App\Models\Producto;
use App\Services\Inventory\Orders\Ocr\FakePedidoOcrProvider;
use App\Services\Inventory\Orders\PedidoDocumentoService;
use App\Services\Inventory\Orders\PedidoOcrService;
use App\Services\Inventory\Orders\PedidoSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\InventoryOrders\Concerns\PedidoPublicacionFixtures;
use Tests\TestCase;

/**
 * Revisión contable U5 (R02/R03): confirmar proveedor y líneas, preservar los
 * campos raw, vincular producto solo por selección explícita y demostrar que un
 * OCR tardío jamás pisa una corrección humana.
 */
class PedidoRevisionCorreccionesTest extends TestCase
{
    use PedidoPublicacionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inventory_orders.enabled' => true]);

        $this->withHeaders(['Accept' => 'application/json']);
        $this->limpiarDocumentosOcr();
    }

    protected function tearDown(): void
    {
        $this->limpiarDocumentosOcr();

        parent::tearDown();
    }

    public function test_patch_confirma_proveedor_y_lineas_sin_pisar_los_campos_raw(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));

        $creado = $this->postJson('/api/web/inventory-orders', $this->payload())->assertStatus(201);
        $uuid = $creado->json('data.uuid');

        $this->patchJson("/api/web/inventory-orders/{$uuid}", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 1,
            'proveedor_nombre_confirmado' => 'OSCAR DARÍO DÍAZ',
            'proveedor_clave' => 'OSCAR-01',
            'referencia_factura' => 'FV-99',
            'lineas' => [[
                'orden' => 1,
                'sku_raw' => 'to000057',
                'descripcion_raw' => "TORNILLO\nCORTAMALEZA",
                'cantidad_esperada' => '4.00',
                'sku_confirmado' => 'TO000057',
                'descripcion_confirmada' => 'TORNILLO CORTAMALEZA',
                'unidad' => 'UND',
            ]],
        ])->assertOk()
            ->assertJsonPath('data.proveedor_nombre_confirmado', 'OSCAR DARÍO DÍAZ')
            ->assertJsonPath('data.proveedor_clave', 'OSCAR-01')
            ->assertJsonPath('data.referencia_factura', 'FV-99')
            ->assertJsonPath('data.lineas.0.sku_confirmado', 'TO000057')
            ->assertJsonPath('data.lineas.0.descripcion_confirmada', 'TORNILLO CORTAMALEZA')
            ->assertJsonPath('data.lineas.0.unidad', 'UND');

        $linea = PedidoInventarioLinea::query()->firstOrFail();

        $this->assertSame('to000057', $linea->sku_raw);
        $this->assertSame("TORNILLO\nCORTAMALEZA", $linea->descripcion_raw);

        $evento = PedidoEvento::query()->where('event_type', 'borrador_actualizado')->firstOrFail();
        $lineasDespues = $evento->datos_despues['lineas'];
        $lineasAntes = $evento->datos_antes['lineas'];

        $this->assertSame('TO000057', $lineasDespues[0]['sku_confirmado']);
        $this->assertSame('UND', $lineasDespues[0]['unidad']);
        $this->assertNull($lineasAntes[0]['sku_confirmado']);
        $this->assertSame('OSCAR-01', $evento->datos_despues['proveedor_clave']);
    }

    public function test_patch_sin_campos_raw_conserva_el_valor_raw_existente(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));

        $creado = $this->postJson('/api/web/inventory-orders', $this->payload())->assertStatus(201);
        $uuid = $creado->json('data.uuid');

        $this->patchJson("/api/web/inventory-orders/{$uuid}", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 1,
            'lineas' => [[
                'orden' => 1,
                'cantidad_esperada' => '4.00',
                'sku_confirmado' => 'TO000057',
            ]],
        ])->assertOk();

        $linea = PedidoInventarioLinea::query()->firstOrFail();

        $this->assertSame('to000057', $linea->sku_raw);
        $this->assertSame("TORNILLO\nCORTAMALEZA", $linea->descripcion_raw);
        $this->assertSame('TO000057', $linea->sku_confirmado);
    }

    public function test_producto_id_requiere_seleccion_explicita_y_coincidencia_de_sku(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));

        $creado = $this->postJson('/api/web/inventory-orders', $this->payload())->assertStatus(201);
        $uuid = $creado->json('data.uuid');

        $categoria = Categoria::create([
            'categoria_nombre' => 'Categoría U5',
            'categoria_tipo' => 'repuesto',
        ]);

        $correcto = Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'TO000057',
            'producto_nombre' => 'TORNILLO CORTAMALEZA',
        ]);

        $senuelo = Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'OTRO-SKU',
            'producto_nombre' => 'TO000057',
        ]);

        // Sin producto_id no hay match difuso por nombre.
        $sinSeleccion = $this->patchJson("/api/web/inventory-orders/{$uuid}", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 1,
            'lineas' => [[
                'orden' => 1,
                'cantidad_esperada' => '4.00',
                'sku_confirmado' => 'TO000057',
            ]],
        ])->assertOk();

        $sinSeleccion->assertJsonPath('data.lineas.0.producto_id', null);

        // Producto explícito que no coincide con el SKU confirmado: 422.
        $this->patchJson("/api/web/inventory-orders/{$uuid}", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 2,
            'lineas' => [[
                'orden' => 1,
                'cantidad_esperada' => '4.00',
                'sku_confirmado' => 'TO000057',
                'producto_id' => $senuelo->producto_id,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('lineas.0.producto_id');

        // Selección explícita correcta: se vincula.
        $this->patchJson("/api/web/inventory-orders/{$uuid}", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 2,
            'lineas' => [[
                'orden' => 1,
                'cantidad_esperada' => '4.00',
                'sku_confirmado' => 'TO000057',
                'producto_id' => $correcto->producto_id,
            ]],
        ])->assertOk()
            ->assertJsonPath('data.lineas.0.producto_id', $correcto->producto_id);
    }

    public function test_ocr_tardio_no_sobreescribe_correcciones_humanas(): void
    {
        $actor = $this->usuario('contabilidad');
        $pedido = $this->pedido();
        $linea = $this->linea($pedido, 1);

        $linea->sku_confirmado = 'HUMANO-001';
        $linea->descripcion_confirmada = 'DESCRIPCION HUMANA';
        $linea->cantidad_esperada = '7.00';
        $linea->save();

        $documento = $this->documentoOcr($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO);
        $this->usarProveedor(new FakePedidoOcrProvider);

        $service = app(PedidoOcrService::class);
        $intento = $service->solicitar($documento, PedidoOcrService::ORIGEN_UPLOAD, $actor);

        $this->assertSame('completado', $service->procesar($intento)->resultado);

        $linea->refresh();
        $this->assertSame('HUMANO-001', $linea->sku_confirmado);
        $this->assertSame('DESCRIPCION HUMANA', $linea->descripcion_confirmada);
        $this->assertSame('7.00', PedidoSnapshot::cantidadCanonica($linea->cantidad_esperada));

        // El resultado OCR queda en su propio intento, separado de la línea.
        $this->assertSame('TO000057', $intento->fresh()->resultado['lines'][0]['sku']['value']);
    }

    public function test_correccion_con_version_desactualizada_es_409(): void
    {
        Sanctum::actingAs($this->usuario('contabilidad'));

        $creado = $this->postJson('/api/web/inventory-orders', $this->payload())->assertStatus(201);
        $uuid = $creado->json('data.uuid');

        $this->patchJson("/api/web/inventory-orders/{$uuid}", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 99,
            'proveedor_nombre_confirmado' => 'Tarde',
        ])->assertStatus(409)->assertJsonPath('current_version', 1);
    }

    public function test_correcciones_denegadas_tras_publicar(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable();

        $this->publicarPedido($pedido, $actor)->assertOk();

        $this->patchJson("/api/web/inventory-orders/{$pedido->uuid}", [
            'client_operation_id' => (string) Str::uuid(),
            'version' => 2,
            'proveedor_nombre_confirmado' => 'Cambio Tardío',
        ])->assertStatus(403);
    }

    public function test_esperado_congelado_no_se_modifica_desde_el_modelo(): void
    {
        $actor = $this->usuario('contabilidad');
        $this->receptorHabilitado();
        $pedido = $this->pedidoPublicable();

        $this->publicarPedido($pedido, $actor)->assertOk();

        $linea = PedidoInventarioLinea::query()->firstOrFail();

        $this->expectException(TransicionInvalidaException::class);

        $linea->cantidad_esperada = '5.00';
        $linea->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'client_operation_id' => (string) Str::uuid(),
            'proveedor_nombre_raw' => 'Proveedor OCR',
            'bodega_id' => $this->bodega()->bodega_id,
            'lineas' => [[
                'orden' => 1,
                'sku_raw' => 'to000057',
                'descripcion_raw' => "TORNILLO\nCORTAMALEZA",
                'cantidad_esperada' => '4.00',
            ]],
        ];
    }
}
