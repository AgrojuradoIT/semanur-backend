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
