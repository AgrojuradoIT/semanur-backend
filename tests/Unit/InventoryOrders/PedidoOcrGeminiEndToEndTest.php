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
 * Extremo a extremo offline para Gemini:
 * Respuesta Gemini estructurada (Mock) → GeminiPedidoOcrProvider → SiigoScreenshotMapper → Contrato.
 */
class PedidoOcrGeminiEndToEndTest extends TestCase
{
    private string $imagen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imagen = tempnam(sys_get_temp_dir(), 'gemini_e2e_');
        file_put_contents($this->imagen, 'bytes-falsos-de-imagen');
    }

    protected function tearDown(): void
    {
        if (is_file($this->imagen)) {
            unlink($this->imagen);
        }

        parent::tearDown();
    }

    public function test_gemini_provider_y_mapper_producen_contrato_valido(): void
    {
        $fixture = (string) file_get_contents(base_path('tests/fixtures/ocr/gemini_pantallazo_o01.json'));
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $fixture),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $proveedor = new GeminiPedidoOcrProvider(
            configuracion: [
                'enabled' => true,
                'api_key' => 'clave-mock',
            ],
            httpClient: $client,
        );

        $crudo = $proveedor->extraer($this->imagen, 'image/jpeg');

        $contrato = (new SiigoScreenshotMapper)->mapear($crudo, [
            'documento_id' => 10,
            'documento_revision' => 1,
            'provider' => 'gemini',
            'provider_version' => 'gemini-2.5-flash-structured-1.0.0',
        ]);

        $this->assertSame(1, $contrato['schema_version']);
        $this->assertSame('gemini', $contrato['provider']);

        // Proveedor extraído
        $this->assertSame('OSCAR DARÍO DÍAZ CASARUBIAS', $contrato['supplier']['value']);

        // Línea del producto extraída y validada
        $this->assertCount(1, $contrato['lines']);
        $linea = $contrato['lines'][0];

        $this->assertSame(5, $linea['source_row']);
        $this->assertSame('TO000057', $linea['sku']['value']);
        $this->assertSame('TORNILLO CORTAMALEZA', $linea['description']['value']);
        $this->assertSame('4.00', $linea['expected_quantity']['value']);

        // Filas ignoradas detectadas correctamente (cabecera, vacía, totales, controles)
        $motivosIgnorados = array_column($contrato['ignored_rows'], 'reason');
        $this->assertContains('cabecera_tabla', $motivosIgnorados);
        $this->assertContains('fila_captura_vacia', $motivosIgnorados);
        $this->assertContains('totales', $motivosIgnorados);
        $this->assertContains('control', $motivosIgnorados);
    }
}
