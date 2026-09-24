<?php

namespace Tests\Unit\InventoryOrders;

use App\Services\Inventory\Orders\Ocr\SiigoScreenshotMapper;
use JsonException;
use Tests\TestCase;

/**
 * Contrato del mapper Siigo (plan sección 4; casos O01/O02/O04/O05/O06).
 *
 * Prueba pura: sin BD, sin red y sin almacenamiento.
 */
class SiigoScreenshotMapperTest extends TestCase
{
    private SiigoScreenshotMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new SiigoScreenshotMapper;
    }

    public function test_o01_fixture_produce_el_contrato_estable_completo(): void
    {
        $contrato = $this->mapearFixture('siigo_pantallazo_o01.json');

        $this->assertSame(1, $contrato['schema_version']);
        $this->assertSame(10, $contrato['document_id']);
        $this->assertSame(1, $contrato['document_revision']);
        $this->assertSame('fake', $contrato['provider']);
        $this->assertSame(SiigoScreenshotMapper::MAPPER_VERSION, $contrato['mapper_version']);

        // Proveedor exacto confirmado por contabilidad (sin autocorrección).
        $this->assertSame('OSCAR DARÍO DÍAZ CASARUBIAS', $contrato['supplier']['value']);
        $this->assertSame('OSCAR DARÍO DÍAZ CASARUBIAS', $contrato['supplier']['raw']);

        // Una sola línea: la fila de captura vacía (Cant=1.00) no crea artículo.
        $this->assertCount(1, $contrato['lines']);

        $linea = $contrato['lines'][0];
        $this->assertSame(1, $linea['source_page']);
        $this->assertSame(5, $linea['source_row']);
        $this->assertNotNull($linea['sku']['raw']);

        // SKU candidato: se conserva tal cual (O/0 no se corrige).
        $this->assertSame('TO000057', $linea['sku']['raw']);
        $this->assertSame('TO000057', $linea['sku']['value']);

        // Descripción multilínea de la misma celda unida.
        $this->assertStringContainsString("\n", (string) $linea['description']['raw']);
        $this->assertSame('TORNILLO CORTAMALEZA', $linea['description']['value']);

        // Cantidad como string decimal canónico.
        $this->assertSame('4.00', $linea['expected_quantity']['raw']);
        $this->assertSame('4.00', $linea['expected_quantity']['value']);

        // Geometría y confianza por campo conservadas; confianza ausente = null.
        $this->assertNotNull($linea['bounding_boxes']['sku']);
        $this->assertIsFloat($linea['sku']['confidence']);
        $this->assertIsFloat($contrato['supplier']['confidence']);

        // Advertencia de ambigüedad O/0: NO bloqueante, pero registrada.
        $codigos = array_column($linea['warnings'], 'code');
        $this->assertContains('sku_posible_confusion_o_cero', $codigos);
        $this->assertFalse($this->advertencia($linea['warnings'], 'sku_posible_confusion_o_cero')['blocking']);

        $this->assertFalse($contrato['requires_human_revision']);
    }

    public function test_o01_excluye_cabecera_proveedor_contacto_totales_y_controles(): void
    {
        $contrato = $this->mapearFixture('siigo_pantallazo_o01.json');
        $motivos = array_column($contrato['ignored_rows'], 'reason');

        foreach (['encabezado_empresa', 'proveedor', 'contacto', 'cabecera_tabla', 'fila_captura_vacia', 'totales', 'control'] as $motivo) {
            $this->assertContains($motivo, $motivos, "Falta la fila ignorada: {$motivo}");
        }

        $captura = $this->ignorada($contrato['ignored_rows'], 'fila_captura_vacia');
        $this->assertSame(6, $captura['source_row']);
        $this->assertSame('1.00', $captura['raw_text']);
    }

    public function test_o02_solo_logo_contacto_y_totales_no_fabrica_proveedor_ni_lineas(): void
    {
        $crudo = $this->crudo([
            $this->fila(1, ['SEMANUR S.A.S.', 'NIT 900.123.456-7']),
            $this->fila(2, ['Dirección:', 'CALLE 10 # 20-30']),
            $this->fila(3, ['SUBTOTAL', '300,000.00']),
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());

        $this->assertNull($contrato['supplier']);
        $this->assertSame([], $contrato['lines']);
        $this->assertTrue($contrato['requires_human_revision']);

        $codigos = array_column($contrato['warnings'], 'code');
        $this->assertContains('proveedor_ausente', $codigos);
        $this->assertContains('sin_lineas_detectadas', $codigos);
        $this->assertContains('cabecera_tabla_no_detectada', $codigos);
    }

    public function test_o04_sku_con_o_cero_e_i_uno_no_se_autocorrige_y_advierte(): void
    {
        $crudo = $this->crudo([
            $this->fila(1, ['Señor(es):', 'PROVEEDOR PRUEBA']),
            $this->cabecera(2),
            $this->filaProducto(3, '1O000O57', 'TORNILLO CORTAMALEZA', '4.00'),
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());
        $linea = $contrato['lines'][0];

        $this->assertSame('1O000O57', $linea['sku']['raw']);
        $this->assertSame('1O000O57', $linea['sku']['value'], 'El mapper jamás reescribe el código.');

        $codigos = array_column($linea['warnings'], 'code');
        $this->assertContains('sku_posible_confusion_o_cero', $codigos);
        $this->assertContains('sku_posible_confusion_i_uno', $codigos);

        foreach ($linea['warnings'] as $warning) {
            if (in_array($warning['code'], ['sku_posible_confusion_o_cero', 'sku_posible_confusion_i_uno'], true)) {
                $this->assertFalse($warning['blocking']);
            }
        }
    }

    public function test_o05_cantidades_invalidas_bloquean_sin_convertir_a_cero(): void
    {
        $casos = [
            '1,234' => 'cantidad_separador_ambiguo',
            '1.234' => 'cantidad_separador_ambiguo',
            '4e2' => 'cantidad_notacion_exponencial',
            '-2.00' => 'cantidad_signo_invalido',
            '0.00' => 'cantidad_cero',
            '4.123' => 'cantidad_separador_ambiguo',
            '4.1234' => 'cantidad_precision_excedida',
            '' => 'cantidad_ilegible',
            'abc' => 'cantidad_invalida',
        ];

        foreach ($casos as $raw => $code) {
            $crudo = $this->crudo([
                $this->fila(1, ['Señor(es):', 'PROVEEDOR PRUEBA']),
                $this->cabecera(2),
                $this->filaProducto(3, 'TO000057', 'TORNILLO CORTAMALEZA', (string) $raw),
            ]);

            $contrato = $this->mapper->mapear($crudo, $this->contexto());
            $linea = $contrato['lines'][0];

            $this->assertNull($linea['expected_quantity']['value'], "Cantidad no bloqueada: {$raw}");
            $this->assertContains($code, array_column($linea['warnings'], 'code'), "Código distinto para: {$raw}");
            $this->assertTrue($contrato['requires_human_revision'], "Revisión no exigida para: {$raw}");
        }
    }

    public function test_o05_coma_decimal_segura_se_normaliza_sin_perder_string(): void
    {
        $crudo = $this->crudo([
            $this->fila(1, ['Señor(es):', 'PROVEEDOR PRUEBA']),
            $this->cabecera(2),
            $this->filaProducto(3, 'TO000057', 'TORNILLO CORTAMALEZA', '4,00'),
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());
        $linea = $contrato['lines'][0];

        $this->assertSame('4,00', $linea['expected_quantity']['raw']);
        $this->assertSame('4.00', $linea['expected_quantity']['value']);
        $this->assertFalse($contrato['requires_human_revision']);
    }

    public function test_o06_proveedor_ausente_es_advertencia_bloqueante_sin_descartar_lineas(): void
    {
        $crudo = $this->crudo([
            $this->cabecera(1),
            $this->filaProducto(2, 'TO000057', 'TORNILLO CORTAMALEZA', '4.00'),
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());

        $this->assertNull($contrato['supplier']);
        $this->assertCount(1, $contrato['lines']);

        $warning = $this->advertencia($contrato['warnings'], 'proveedor_ausente');
        $this->assertTrue($warning['blocking']);
        $this->assertTrue($contrato['requires_human_revision']);
    }

    public function test_fila_parcial_sin_descripcion_no_se_descarta_en_silencio(): void
    {
        $crudo = $this->crudo([
            $this->fila(1, ['Señor(es):', 'PROVEEDOR PRUEBA']),
            $this->cabecera(2),
            $this->filaProducto(3, 'TO000057', '', '4.00'),
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());
        $linea = $contrato['lines'][0];

        $this->assertNull($linea['description']['value']);
        $this->assertContains('descripcion_ausente', array_column($linea['warnings'], 'code'));
        $this->assertTrue($contrato['requires_human_revision']);
    }

    public function test_cantidad_cero_explicita_en_fila_legible_no_crea_cantidad_esperada(): void
    {
        $crudo = $this->crudo([
            $this->fila(1, ['Señor(es):', 'PROVEEDOR PRUEBA']),
            $this->cabecera(2),
            $this->filaProducto(3, 'TO000057', 'TORNILLO CORTAMALEZA', '0'),
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());

        $this->assertNull($contrato['lines'][0]['expected_quantity']['value']);
        $this->assertContains('cantidad_cero', array_column($contrato['lines'][0]['warnings'], 'code'));
    }

    /**
     * @return array<string, mixed>
     */
    private function mapearFixture(string $fixture): array
    {
        $ruta = base_path('tests/fixtures/ocr/'.$fixture);
        $contenido = file_get_contents($ruta);
        $this->assertIsString($contenido);

        try {
            $crudo = json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->fail('Fixture inválido: '.$exception->getMessage());
        }

        return $this->mapper->mapear($crudo, $this->contexto());
    }

    public function test_celdas_que_colisionan_en_la_misma_columna_se_unen_sin_perder_texto(): void
    {
        $crudo = $this->crudo([
            $this->cabecera(1),
            [
                'row' => 2,
                'cells' => [
                    ['column' => 1, 'text' => 'TO000057', 'confidence' => 0.95, 'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 50, 'height' => 20]],
                    // Dos celdas asignadas a la columna 2 (Descripcion)
                    ['column' => 2, 'text' => 'TORNILLO', 'confidence' => 0.90, 'bounding_box' => ['x' => 70, 'y' => 10, 'width' => 50, 'height' => 20]],
                    ['column' => 2, 'text' => 'CORTAMALEZA', 'confidence' => 0.85, 'bounding_box' => ['x' => 130, 'y' => 10, 'width' => 60, 'height' => 20]],
                    ['column' => 4, 'text' => '4.00', 'confidence' => 0.92, 'bounding_box' => ['x' => 200, 'y' => 10, 'width' => 30, 'height' => 20]],
                ],
            ],
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());

        $this->assertCount(1, $contrato['lines']);
        $linea = $contrato['lines'][0];
        $this->assertSame('TORNILLO CORTAMALEZA', $linea['description']['value']);
        $this->assertSame(0.85, $linea['description']['confidence']);
        $this->assertSame(['x' => 70, 'y' => 10, 'width' => 120, 'height' => 20], $linea['bounding_boxes']['description']);
    }

    public function test_pantallazo_real_siigo_detecta_proveedores_cabecera_producto_y_excluye_fila_buscar(): void
    {
        $crudo = $this->crudo([
            $this->fila(1, ['Tipo', 'DS - 1 - Documento Soporte Electronico', 'Número', '2433']),
            $this->fila(2, ['Fecha de elaboración', '09/09/2026', 'No. comprobante proveedor', 'DS']),
            $this->fila(3, ['Proveedores', 'Oscar Darío Diaz Casarubias', 'Ver perfil', 'Centro de costo', 'MASSYF2625N1-1']),
            $this->fila(4, ['Contacto', 'Oscar Darío Diaz Casarubias (OSDADIC...']),
            $this->fila(5, ['Descuento en porcentaje']),
            $this->fila(6, ['#', 'Tipo', 'Producto', 'Descripción', 'Bodega', 'Cant', 'Valor Unitario', 'Descuento', 'Impuesto Cargo', 'Impuesto Retención', 'Valor Total']),
            $this->fila(7, ['1', 'Producto', 'TO000057', 'TORNILLO CORTAMALEZA', 'Repuestos', '4.00', '75,000.00', '0.00', '', '', '300,000.00']),
            $this->fila(8, ['2', 'Producto', 'Buscar', '', '', '1.00', '0.00', '0.00', '', '', '0.00']),
            $this->fila(9, ['Total Bruto:', '300,000.00']),
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());

        $this->assertNotNull($contrato['supplier']);
        $this->assertSame('Oscar Darío Diaz Casarubias', $contrato['supplier']['value']);
        $this->assertCount(1, $contrato['lines']);

        $linea = $contrato['lines'][0];
        $this->assertSame('TO000057', $linea['sku']['value']);
        $this->assertSame('TORNILLO CORTAMALEZA', $linea['description']['value']);
        $this->assertSame('4.00', $linea['expected_quantity']['value']);

        $this->assertEmpty(array_filter($contrato['warnings'], fn ($w) => $w['blocking'] ?? false));
        $this->assertFalse($contrato['requires_human_revision']);

        $motivos = array_column($contrato['ignored_rows'], 'reason');
        $this->assertContains('fila_captura_vacia', $motivos);
        $this->assertContains('proveedor', $motivos);
        $this->assertContains('cabecera_tabla', $motivos);
        $this->assertContains('totales', $motivos);
    }

    public function test_fix3_producto_con_total_en_nombre_no_se_descarta_como_totales(): void
    {
        $crudo = $this->crudo([
            $this->fila(1, ['Señor(es):', 'PROVEEDOR PRUEBA']),
            $this->cabecera(2),
            $this->filaProducto(3, 'AC2577', 'ACEITE TOTAL 15W40', '2.00'),
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());

        $this->assertCount(1, $contrato['lines']);
        $linea = $contrato['lines'][0];
        $this->assertSame('AC2577', $linea['sku']['value']);
        $this->assertSame('ACEITE TOTAL 15W40', $linea['description']['value']);
        $this->assertSame('2.00', $linea['expected_quantity']['value']);
        $this->assertFalse($contrato['requires_human_revision']);
    }

    public function test_fix3_producto_con_contacto_en_nombre_no_se_descarta_como_contacto(): void
    {
        $crudo = $this->crudo([
            $this->fila(1, ['Señor(es):', 'PROVEEDOR PRUEBA']),
            $this->cabecera(2),
            $this->filaProducto(3, 'LC8890', 'LIMPIADOR DE CONTACTO', '3.00'),
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());

        $this->assertCount(1, $contrato['lines']);
        $linea = $contrato['lines'][0];
        $this->assertSame('LC8890', $linea['sku']['value']);
        $this->assertSame('LIMPIADOR DE CONTACTO', $linea['description']['value']);
        $this->assertSame('3.00', $linea['expected_quantity']['value']);
        $this->assertFalse($contrato['requires_human_revision']);
    }

    public function test_fix3_continuacion_desalineada_exige_revision_en_vez_de_perderse(): void
    {
        $crudo = $this->crudo([
            $this->fila(1, ['Señor(es):', 'PROVEEDOR PRUEBA']),
            $this->cabecera(2),
            $this->filaProducto(3, 'TO000057', 'TORNILLO', '4.00'),
            // Segunda línea visual desplazada en x: el puente la deja en una
            // columna nueva (7) fuera de SKU/descripción/cantidad (1/2/4).
            ['row' => 4, 'cells' => [
                ['column' => 7, 'text' => 'CORTAMALEZA', 'confidence' => 0.85, 'bounding_box' => ['x' => 206, 'y' => 280, 'width' => 120, 'height' => 24]],
            ]],
        ]);

        $contrato = $this->mapper->mapear($crudo, $this->contexto());

        // La línea legible se conserva; la continuación queda registrada...
        $this->assertCount(1, $contrato['lines']);
        $this->assertSame('fila_vacia', $this->ignorada($contrato['ignored_rows'], 'fila_vacia')['reason']);

        // ...pero ya no se pierde en silencio: exige revisión humana.
        $this->assertTrue($contrato['requires_human_revision']);
        $advertencia = $this->advertencia($contrato['warnings'], 'fila_posible_continuacion');
        $this->assertTrue($advertencia['blocking']);
    }

    /**
     * @return array<string, mixed>
     */
    private function contexto(): array
    {
        return [
            'documento_id' => 10,
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
    private function cabecera(int $numero): array
    {
        return $this->fila($numero, ['BUSCAR', 'DESCRIPCIÓN', 'BODEGA', 'CANT', 'VALOR UNIT.', 'VALOR TOTAL']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filaProducto(int $numero, string $sku, string $descripcion, string $cantidad): array
    {
        return $this->fila($numero, [$sku, $descripcion, 'Repuestos', $cantidad, '75,000.00', '300,000.00']);
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     * @return array<string, mixed>
     */
    private function advertencia(array $warnings, string $code): array
    {
        foreach ($warnings as $warning) {
            if (($warning['code'] ?? null) === $code) {
                return $warning;
            }
        }

        $this->fail("No se encontró la advertencia {$code}.");
    }

    /**
     * @param  list<array<string, mixed>>  $ignoradas
     * @return array<string, mixed>
     */
    private function ignorada(array $ignoradas, string $motivo): array
    {
        foreach ($ignoradas as $fila) {
            if (($fila['reason'] ?? null) === $motivo) {
                return $fila;
            }
        }

        $this->fail("No se encontró la fila ignorada {$motivo}.");
    }
}
