<?php

namespace Tests\Unit\InventoryOrders;

use App\Services\Inventory\Orders\Ocr\GeminiPedidoOcrProvider;
use App\Services\Inventory\Orders\Ocr\SiigoScreenshotMapper;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * Variante Documento Soporte (DS-1): cabecera con columna `Producto` (= SKU),
 * pastillas `Proveedores:`/`Contacto:` y descripciones de 2 líneas.
 *
 * Pruebas puras/offline: sin BD, sin red real y sin almacenamiento
 * (la imagen es un temporal con bytes falsos + Guzzle MockHandler).
 */
class SiigoScreenshotMapperDsTest extends TestCase
{
    private SiigoScreenshotMapper $mapper;

    private string $imagen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new SiigoScreenshotMapper;
        $this->imagen = tempnam(sys_get_temp_dir(), 'gemini_ds_');
        file_put_contents($this->imagen, 'bytes-falsos-de-imagen-ds');
    }

    protected function tearDown(): void
    {
        if (is_file($this->imagen)) {
            unlink($this->imagen);
        }

        parent::tearDown();
    }

    public function test_ds_fixture_resuelve_cabecera_linea_y_proveedor(): void
    {
        $contrato = $this->mapearFixtureDs();

        // La cabecera DS se detectó: el trío sku/descripción/cantidad resolvió.
        $this->assertSame('Oscar Darío Diaz Casarubias', $contrato['supplier']['value']);
        $this->assertSame('Oscar Darío Diaz Casarubias', $contrato['supplier']['raw']);

        // Una sola línea: TO000057 con cantidad 4.00.
        $this->assertCount(1, $contrato['lines']);
        $linea = $contrato['lines'][0];
        $this->assertSame(4, $linea['source_row']);
        $this->assertSame('TO000057', $linea['sku']['value']);
        $this->assertSame('TORNILLO CORTAMALEZA', $linea['description']['value']);
        $this->assertStringContainsString("\n", (string) $linea['description']['raw']);
        $this->assertSame('4.00', $linea['expected_quantity']['value']);

        // La fila de datos con "Producto" (columna Tipo) no se tragó como cabecera.
        $cabeceras = array_filter(
            $contrato['ignored_rows'],
            static fn (array $fila): bool => ($fila['reason'] ?? null) === 'cabecera_tabla',
        );
        $this->assertCount(1, $cabeceras);

        // Pastillas + Buscar vacío ignorados sin advertencias bloqueantes.
        $motivos = array_column($contrato['ignored_rows'], 'reason');
        $this->assertContains('proveedor', $motivos);
        $this->assertContains('contacto', $motivos);
        $this->assertContains('fila_vacia', $motivos);
        $this->assertSame([], $contrato['warnings']);
        $this->assertFalse($contrato['requires_human_revision']);
    }

    public function test_ds_producto_con_palabra_total_y_cantidad_sobrevive(): void
    {
        $crudo = $this->crudo([
            $this->fila(1, ['Proveedores:', 'PROVEEDOR PRUEBA']),
            $this->cabeceraDs(2),
            $this->filaProductoDs(3, '2', 'AC000123', 'ACEITE TOTAL 15W40', '2.00'),
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());

        $this->assertCount(1, $contrato['lines']);
        $this->assertSame('AC000123', $contrato['lines'][0]['sku']['value']);
        $this->assertSame('2.00', $contrato['lines'][0]['expected_quantity']['value']);
        $this->assertNotContains('totales', array_column($contrato['ignored_rows'], 'reason'));
    }

    public function test_ds_producto_con_palabra_contacto_y_cantidad_sobrevive(): void
    {
        $crudo = $this->crudo([
            $this->fila(1, ['Proveedores:', 'PROVEEDOR PRUEBA']),
            $this->cabeceraDs(2),
            $this->filaProductoDs(3, '3', 'LC000456', 'LIMPIADOR DE CONTACTO', '5.00'),
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());

        $this->assertCount(1, $contrato['lines']);
        $this->assertSame('LC000456', $contrato['lines'][0]['sku']['value']);
        $this->assertSame('5.00', $contrato['lines'][0]['expected_quantity']['value']);
        $this->assertNotContains('contacto', array_column($contrato['ignored_rows'], 'reason'));
    }

    public function test_ds_etiqueta_genuina_sin_contexto_sigue_filtrada(): void
    {
        $crudo = $this->crudo([
            $this->fila(1, ['Proveedores:', 'PROVEEDOR PRUEBA']),
            $this->cabeceraDs(2),
            $this->filaProductoDs(3, '1', 'TO000057', 'TORNILLO CORTAMALEZA', '4.00'),
            $this->fila(4, ['', '', '', 'TOTAL', '', '', '', '', '', '', '300,000.00']),
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());

        $this->assertCount(1, $contrato['lines']);
        $this->assertContains('totales', array_column($contrato['ignored_rows'], 'reason'));
    }

    public function test_ds_contacto_es_respaldo_solo_sin_proveedores(): void
    {
        // Sin fila Proveedores: el valor de Contacto respalda al proveedor.
        $crudo = $this->crudo([
            $this->fila(1, ['Contacto:', 'JUAN PEREZ']),
            $this->cabeceraDs(2),
            $this->filaProductoDs(3, '1', 'TO000057', 'TORNILLO CORTAMALEZA', '4.00'),
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());

        $this->assertSame('JUAN PEREZ', $contrato['supplier']['value']);
        $this->assertContains('contacto', array_column($contrato['ignored_rows'], 'reason'));
        $this->assertNotContains('proveedor', array_column($contrato['ignored_rows'], 'reason'));
        $this->assertNotContains('proveedor_ausente', array_column($contrato['warnings'], 'code'));
        $this->assertCount(1, $contrato['lines']);
    }

    public function test_ds_proveedores_gana_sobre_contacto(): void
    {
        $crudo = $this->crudo([
            $this->fila(1, ['Proveedores:', 'OSCAR DARIO']),
            $this->fila(2, ['Contacto:', 'JUAN PEREZ']),
            $this->cabeceraDs(3),
            $this->filaProductoDs(4, '1', 'TO000057', 'TORNILLO CORTAMALEZA', '4.00'),
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());

        $this->assertSame('OSCAR DARIO', $contrato['supplier']['value']);
        $this->assertNotContains('proveedor_ausente', array_column($contrato['warnings'], 'code'));
    }

    /**
     * @return array<string, mixed>
     */
    private function mapearFixtureDs(): array
    {
        $cuerpo = (string) file_get_contents(base_path('tests/fixtures/ocr/gemini_pantallazo_ds01.json'));
        $this->assertNotSame('', $cuerpo);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $cuerpo),
        ]);
        $proveedor = new GeminiPedidoOcrProvider(
            configuracion: ['enabled' => true, 'api_key' => 'clave-mock'],
            httpClient: new Client(['handler' => HandlerStack::create($mock)]),
        );

        $crudo = $proveedor->extraer($this->imagen, 'image/jpeg');

        return $this->mapper->mapear($crudo, [
            'documento_id' => 11,
            'documento_revision' => 1,
            'provider' => 'gemini',
            'provider_version' => 'gemini-2.5-flash-structured-1.0.0',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function contexto(): array
    {
        return [
            'documento_id' => 11,
            'documento_revision' => 1,
            'provider' => 'fake',
            'provider_version' => 'fake-1.0.0',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    private function crudo(array $filas): array
    {
        return [
            'provider' => 'test',
            'provider_version' => 'test-1',
            'full_text' => '',
            'pages' => [[
                'page_number' => 1,
                'width' => 1000,
                'height' => 2000,
                'rows' => $filas,
            ]],
        ];
    }

    /**
     * @param  list<string>  $textos
     * @return array<string, mixed>
     */
    private function fila(int $numero, array $textos): array
    {
        $cells = [];
        $columna = 1;

        foreach ($textos as $texto) {
            $cells[] = [
                'column' => $columna++,
                'text' => $texto,
                'confidence' => 0.9,
                'bounding_box' => ['x' => 1, 'y' => 1, 'width' => 10, 'height' => 10],
            ];
        }

        return ['row' => $numero, 'cells' => $cells];
    }

    /**
     * @return array<string, mixed>
     */
    private function cabeceraDs(int $numero): array
    {
        return $this->fila($numero, ['#', 'Tipo', 'Producto', 'Descripción', 'Bodega', 'Cant', 'Valor Unitario', 'Descuento', 'Impuesto Cargo', 'Impuesto Retención', 'Valor Total']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filaProductoDs(int $numero, string $item, string $sku, string $descripcion, string $cantidad): array
    {
        return $this->fila($numero, [$item, 'Producto', $sku, $descripcion, 'Repuestos', $cantidad, '75,000.00', '0.00', '', '', '300,000.00']);
    }

}
