<?php

namespace Tests\Unit\InventoryOrders;

use App\Services\Inventory\Orders\Ocr\SiigoScreenshotMapper;
use App\Services\Inventory\Orders\Ocr\VisionResponseBridge;
use Tests\TestCase;

/**
 * Extremo a extremo offline (U12): respuesta Vision enlatada → puente →
 * mapper existente → contrato estable de la sección 4.
 *
 * Sin red, sin base de datos, sin credenciales: el JSON del fixture es
 * sintético y el mapper NO se modifica.
 *
 * Nota honesta (ver U12-vision.md §2): el puente geométrico mantiene cada
 * línea visual en su propia fila, así que `CORTAMALEZA` llega como fila de
 * continuación y el mapper la marca con `sku_ausente` bloqueante (exige
 * revisión humana) en vez de unirla en silencio. El plan §4 lo respalda:
 * una fila parcial nunca se descarta ni se inventa.
 */
class PedidoOcrVisionEndToEndTest extends TestCase
{
    public function test_vision_puente_mapper_produce_contrato_o01(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/fixtures/ocr/vision_document_text_o01.json')),
            true,
        );
        $this->assertIsArray($fixture);

        $crudo = (new VisionResponseBridge)->convertir($fixture);
        $crudo['provider'] = 'google';
        $crudo['provider_version'] = 'google-vision-document-text-1.0.0';

        $contrato = (new SiigoScreenshotMapper)->mapear($crudo, [
            'documento_id' => 1,
            'documento_revision' => 1,
            'provider' => 'google',
            'provider_version' => 'google-vision-document-text-1.0.0',
        ]);

        $this->assertSame(1, $contrato['schema_version']);
        $this->assertSame('google', $contrato['provider']);

        // Proveedor exacto del pantallazo.
        $this->assertSame('OSCAR DARÍO DÍAZ CASARUBIAS', $contrato['supplier']['value']);
        $this->assertSame('OSCAR DARÍO DÍAZ CASARUBIAS', $contrato['supplier']['raw']);

        // Línea del producto: SKU sin corregir + cantidad como string.
        $this->assertCount(2, $contrato['lines']);

        $linea = $contrato['lines'][0];
        $this->assertSame(5, $linea['source_row']);
        $this->assertSame('TO000057', $linea['sku']['raw']);
        $this->assertSame('TO000057', $linea['sku']['value'], 'El SKU jamás se autocorrige (O/0).');

        $codigos = array_column($linea['warnings'], 'code');
        $this->assertContains('sku_posible_confusion_o_cero', $codigos);

        $advertencia = collect($linea['warnings'])->firstWhere('code', 'sku_posible_confusion_o_cero');
        $this->assertFalse($advertencia['blocking']);

        $this->assertSame('TORNILLO', $linea['description']['value']);
        $this->assertSame('4.00', $linea['expected_quantity']['raw']);
        $this->assertSame('4.00', $linea['expected_quantity']['value']);

        // Continuación multilínea: fila propia con advertencia bloqueante.
        $continuacion = $contrato['lines'][1];
        $this->assertSame('CORTAMALEZA', $continuacion['description']['value']);
        $this->assertNull($continuacion['sku']['value']);

        $codigosContinuacion = array_column($continuacion['warnings'], 'code');
        $this->assertContains('sku_ausente', $codigosContinuacion);
        $this->assertTrue($contrato['requires_human_revision']);

        // Fila de captura vacía y contexto ignorados, sin líneas fantasma.
        $ignoradas = [];
        foreach ($contrato['ignored_rows'] as $ignorada) {
            $ignoradas[$ignorada['source_row']] = $ignorada['reason'];
        }

        $this->assertSame('cabecera_tabla', $ignoradas[4]);
        $this->assertSame('fila_captura_vacia', $ignoradas[7]);
        $this->assertSame('totales', $ignoradas[8]);
        $this->assertSame('control', $ignoradas[9]);
        $this->assertSame('proveedor', $ignoradas[2]);
    }
}
