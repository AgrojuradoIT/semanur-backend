<?php

namespace Tests\Unit\InventoryOrders;

use App\Services\Inventory\Orders\Ocr\OcrException;
use App\Services\Inventory\Orders\Ocr\VisionResponseBridge;
use Tests\TestCase;

/**
 * Puente Vision → crudo neutral (U12).
 *
 * Todo offline: el fixture `vision_document_text_o01.json` es sintético pero
 * representativo (misma captura Siigo que O01, página 1240x2200, cajas por
 * palabra y por símbolo + confianzas plausibles, sin datos privados reales).
 */
class PedidoVisionResponseBridgeTest extends TestCase
{
    private function crudoO01(): array
    {
        $ruta = base_path('tests/fixtures/ocr/vision_document_text_o01.json');
        $datos = json_decode((string) file_get_contents($ruta), true);

        $this->assertIsArray($datos, 'El fixture Vision O01 debe ser JSON válido.');

        return (new VisionResponseBridge)->convertir($datos);
    }

    public function test_reconstruye_filas_y_celdas_del_pantallazo_o01(): void
    {
        $crudo = $this->crudoO01();

        $this->assertSame('', $crudo['full_text'] === '' ? '' : substr($crudo['full_text'], 0, 0));
        $this->assertStringContainsString('TORNILLO', $crudo['full_text']);
        $this->assertCount(1, $crudo['pages']);

        $pagina = $crudo['pages'][0];
        $this->assertSame(1, $pagina['page_number']);
        $this->assertSame(1240, $pagina['width']);
        $this->assertSame(2200, $pagina['height']);

        $filas = $pagina['rows'];
        $this->assertCount(9, $filas, 'Se esperan 9 filas visuales: empresa, proveedor, dirección, cabecera, producto, continuación, captura vacía, totales y controles.');

        $textos = array_map(
            static fn (array $fila): string => implode(' | ', array_column($fila['cells'], 'text')),
            $filas,
        );

        $this->assertStringContainsString('Señor(es):', $textos[1]);
        $this->assertStringContainsString('CASARUBIAS', $textos[1]);
        $this->assertStringContainsString('BUSCAR', $textos[3]);
        $this->assertStringContainsString('CANT', $textos[3]);
        $this->assertStringContainsString('TO000057', $textos[4]);
        $this->assertStringContainsString('4.00', $textos[4]);
    }

    public function test_descripcion_multilinea_queda_en_filas_separadas(): void
    {
        $crudo = $this->crudoO01();
        $filas = $crudo['pages'][0]['rows'];

        $this->assertStringContainsString('TORNILLO', implode(' | ', array_column($filas[4]['cells'], 'text')));
        $this->assertStringNotContainsString('CORTAMALEZA', implode(' | ', array_column($filas[4]['cells'], 'text')));
        $this->assertSame('CORTAMALEZA', implode(' | ', array_column($filas[5]['cells'], 'text')));
    }

    public function test_totales_presentes_y_sin_filas_fantasma(): void
    {
        $crudo = $this->crudoO01();
        $filas = $crudo['pages'][0]['rows'];

        $textos = array_map(
            static fn (array $fila): string => implode(' ', array_column($fila['cells'], 'text')),
            $filas,
        );

        $this->assertStringContainsString('SUBTOTAL', $textos[7]);

        foreach ($filas as $fila) {
            foreach ($fila['cells'] as $celda) {
                $this->assertNotSame('', trim($celda['text']), 'Nunca se inventan celdas vacías ni filas fantasma.');
            }
        }

        $this->assertSame('1.00', implode(' ', array_column($filas[6]['cells'], 'text')));
        $this->assertCount(1, $filas[6]['cells']);
    }

    public function test_filas_ordenadas_y_columnas_alineadas_geometricamente(): void
    {
        $crudo = $this->crudoO01();
        $filas = $crudo['pages'][0]['rows'];

        $numeros = array_column($filas, 'row');
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], $numeros);

        $porTexto = [];
        foreach ($filas as $fila) {
            foreach ($fila['cells'] as $celda) {
                $porTexto[$celda['text']][] = $celda['column'];
            }
        }

        // La cabecera y el dato comparten columna geométrica (sin semántica).
        $this->assertSame($porTexto['BUSCAR'], $porTexto['TO000057']);
        $this->assertSame($porTexto['DESCRIPCIÓN'], $porTexto['TORNILLO']);
        $this->assertSame($porTexto['CANT'], $porTexto['4.00']);
        $this->assertSame($porTexto['CANT'], $porTexto['1.00']);
    }

    public function test_confianza_es_minimo_de_simbolos_y_caja_es_union(): void
    {
        $puente = new VisionResponseBridge;

        $crudo = $puente->convertir([
            'fullTextAnnotation' => [
                'pages' => [[
                    'width' => 500,
                    'height' => 500,
                    'blocks' => [[
                        'paragraphs' => [[
                            'words' => [[
                                'symbols' => [
                                    ['text' => 'A', 'confidence' => 0.9, 'boundingBox' => $this->caja(10, 10, 10, 20)],
                                    ['text' => 'B', 'confidence' => 0.7, 'boundingBox' => $this->caja(20, 12, 10, 20)],
                                ],
                            ]],
                        ]],
                    ]],
                ]],
            ],
        ]);

        $celda = $crudo['pages'][0]['rows'][0]['cells'][0];

        $this->assertSame('AB', $celda['text']);
        $this->assertSame(0.7, $celda['confidence'], 'La confianza de celda es el mínimo, nunca un invento optimista.');
        $this->assertSame(['x' => 10, 'y' => 10, 'width' => 20, 'height' => 22], $celda['bounding_box']);
    }

    public function test_acepta_respuesta_completa_y_vertices_normalizados(): void
    {
        $puente = new VisionResponseBridge;

        $respuesta = [
            'responses' => [[
                'textAnnotations' => [['description' => "X\nY"]],
                'fullTextAnnotation' => [
                    'pages' => [[
                        'width' => 1000,
                        'height' => 1000,
                        'blocks' => [[
                            'paragraphs' => [[
                                'words' => [
                                    ['symbols' => [['text' => 'X', 'confidence' => 0.9]], 'boundingBox' => $this->caja(100, 100, 50, 20), 'confidence' => 0.9],
                                    ['symbols' => [['text' => 'Y', 'confidence' => 0.8]], 'boundingBox' => ['normalizedVertices' => [
                                        ['x' => 0.3, 'y' => 0.3], ['x' => 0.35, 'y' => 0.3],
                                        ['x' => 0.35, 'y' => 0.32], ['x' => 0.3, 'y' => 0.32],
                                    ]], 'confidence' => 0.8],
                                ],
                            ]],
                        ]],
                    ]],
                ],
            ]],
        ];

        $crudo = $puente->convertir($respuesta);

        $this->assertSame("X\nY", $crudo['full_text']);
        $this->assertCount(2, $crudo['pages'][0]['rows'], 'Y queda en otra fila: banda vertical distinta.');
        $this->assertSame(['x' => 300, 'y' => 300, 'width' => 50, 'height' => 20], $crudo['pages'][0]['rows'][1]['cells'][0]['bounding_box']);
    }

    public function test_sin_geometria_es_terminal(): void
    {
        $this->expectException(OcrException::class);

        try {
            (new VisionResponseBridge)->convertir(['textAnnotations' => [['description' => 'algo']]]);
            $this->fail('Sin fullTextAnnotation debe fallar.');
        } catch (OcrException $exception) {
            $this->assertSame('respuesta_sin_geometria', $exception->errorCode());
            $this->assertFalse($exception->esReintentable());
            throw $exception;
        }
    }

    /**
     * @return array{vertices: list<array{x: int, y: int}>}
     */
    private function caja(int $x, int $y, int $w, int $h): array
    {
        return ['vertices' => [
            ['x' => $x, 'y' => $y],
            ['x' => $x + $w, 'y' => $y],
            ['x' => $x + $w, 'y' => $y + $h],
            ['x' => $x, 'y' => $y + $h],
        ]];
    }
}
