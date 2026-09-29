<?php

namespace Tests\Unit\InventoryOrders;

use App\Services\Inventory\Orders\Ocr\GeminiPedidoOcrProvider;
use App\Services\Inventory\Orders\Ocr\OcrException;
use App\Services\Inventory\Orders\Ocr\OcrProveedorNoHabilitadoException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * Pruebas unitarias para GeminiPedidoOcrProvider.
 *
 * Cero red real: todo el tráfico HTTP pasa por Guzzle MockHandler.
 */
class GeminiPedidoOcrProviderTest extends TestCase
{
    private string $imagen;

    /** @var list<array<string, mixed>> */
    private array $historial = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->imagen = tempnam(sys_get_temp_dir(), 'gemini_img_');
        file_put_contents($this->imagen, 'bytes-falsos-de-imagen-para-gemini');
    }

    protected function tearDown(): void
    {
        if (is_file($this->imagen)) {
            unlink($this->imagen);
        }

        parent::tearDown();
    }

    public function test_falla_cerrado_si_no_esta_habilitado(): void
    {
        $mock = new MockHandler([]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $proveedor = new GeminiPedidoOcrProvider(
            configuracion: [
                'enabled' => false,
                'api_key' => 'clave-falsa',
            ],
            httpClient: $client,
        );

        $this->expectException(OcrProveedorNoHabilitadoException::class);
        $this->expectExceptionMessage('proveedor_no_habilitado: el adaptador de Google Gemini no está habilitado');

        $proveedor->extraer($this->imagen, 'image/jpeg');
        $this->assertCount(0, $mock);
    }

    public function test_falla_cerrado_sin_api_key(): void
    {
        $mock = new MockHandler([]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $proveedor = new GeminiPedidoOcrProvider(
            configuracion: [
                'enabled' => true,
                'api_key' => '',
            ],
            httpClient: $client,
        );

        $this->expectException(OcrProveedorNoHabilitadoException::class);
        $this->expectExceptionMessage('proveedor_no_habilitado: proveedor gemini seleccionado pero sin GEMINI_API_KEY');

        $proveedor->extraer($this->imagen, 'image/jpeg');
        $this->assertCount(0, $mock);
    }

    public function test_falla_con_imagen_inexistente(): void
    {
        $proveedor = new GeminiPedidoOcrProvider(
            configuracion: [
                'enabled' => true,
                'api_key' => 'clave-valida',
            ],
        );

        $this->expectException(OcrException::class);
        try {
            $proveedor->extraer('/ruta/inexistente/img.jpg', 'image/jpeg');
        } catch (OcrException $e) {
            $this->assertSame('imagen_ilegible', $e->errorCode());
            $this->assertFalse($e->esReintentable());
            throw $e;
        }
    }

    public function test_envia_cuerpo_esperado_y_parsea_filas_correctamente(): void
    {
        $fixturePath = base_path('tests/fixtures/ocr/gemini_pantallazo_o01.json');
        $this->assertFileExists($fixturePath);
        $cuerpoFixture = (string) file_get_contents($fixturePath);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $cuerpoFixture),
        ]);

        $this->historial = [];
        $history = Middleware::history($this->historial);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);
        $client = new Client(['handler' => $handlerStack]);

        $proveedor = new GeminiPedidoOcrProvider(
            configuracion: [
                'enabled' => true,
                'api_key' => 'mi-gemini-key-123',
                'model' => 'gemini-2.5-flash',
                'timeout' => 45,
            ],
            httpClient: $client,
        );

        $resultado = $proveedor->extraer($this->imagen, 'image/jpeg');

        // Verificar llamada HTTP
        $this->assertCount(1, $this->historial);
        /** @var Request $solicitud */
        $solicitud = $this->historial[0]['request'];
        $this->assertSame('POST', $solicitud->getMethod());
        $this->assertStringContainsString('key=mi-gemini-key-123', (string) $solicitud->getUri());
        $this->assertStringContainsString('gemini-2.5-flash:generateContent', (string) $solicitud->getUri());

        $cuerpoEnviado = json_decode((string) $solicitud->getBody(), true);
        $this->assertIsArray($cuerpoEnviado);
        $this->assertEquals(0.0, $cuerpoEnviado['generationConfig']['temperature']);
        $this->assertSame('application/json', $cuerpoEnviado['generationConfig']['response_mime_type']);

        $inlineData = $cuerpoEnviado['contents'][0]['parts'][1]['inline_data'];
        $this->assertSame('image/jpeg', $inlineData['mime_type']);
        $this->assertSame(base64_encode(file_get_contents($this->imagen)), $inlineData['data']);

        // Verificar estructura neutral devuelta
        $this->assertSame('gemini', $resultado['provider']);
        $this->assertNotEmpty($resultado['full_text']);
        $this->assertCount(1, $resultado['pages']);

        $filas = $resultado['pages'][0]['rows'];
        $this->assertCount(8, $filas);

        // Fila 4 debe tener las cabeceras
        $filaCabecera = $filas[3];
        $this->assertSame(4, $filaCabecera['row']);
        $this->assertSame('BUSCAR', $filaCabecera['cells'][0]['text']);
        $this->assertSame('DESCRIPCIÓN', $filaCabecera['cells'][1]['text']);
        $this->assertSame('CANT', $filaCabecera['cells'][3]['text']);

        // Fila 5 debe tener el producto
        $filaProducto = $filas[4];
        $this->assertSame('TO000057', $filaProducto['cells'][0]['text']);
        $this->assertSame('TORNILLO CORTAMALEZA', $filaProducto['cells'][1]['text']);
        $this->assertSame('4.00', $filaProducto['cells'][3]['text']);
    }

    public function test_clasifica_errores_terminales_400_401_403(): void
    {
        $casos = [
            400 => 'imagen_rechazada',
            401 => 'credenciales_rechazadas',
            403 => 'credenciales_rechazadas',
        ];

        foreach ($casos as $statusCode => $errorCodeEsperado) {
            $mock = new MockHandler([
                new Response($statusCode, ['Content-Type' => 'application/json'], json_encode([
                    'error' => ['code' => $statusCode, 'message' => "Fallo $statusCode"],
                ])),
            ]);
            $client = new Client(['handler' => HandlerStack::create($mock)]);

            $proveedor = new GeminiPedidoOcrProvider(
                configuracion: ['enabled' => true, 'api_key' => 'key-test'],
                httpClient: $client,
            );

            try {
                $proveedor->extraer($this->imagen, 'image/jpeg');
                $this->fail("Esperaba excepción para HTTP $statusCode");
            } catch (OcrException $e) {
                $this->assertSame($errorCodeEsperado, $e->errorCode());
                $this->assertFalse($e->esReintentable(), "HTTP $statusCode no debe ser reintentable.");
            }
        }
    }

    public function test_clasifica_errores_reintentables_429_500_red(): void
    {
        $casos = [
            429 => 'limite_excedido',
            500 => 'error_transitorio_gemini',
            503 => 'error_transitorio_gemini',
        ];

        foreach ($casos as $statusCode => $errorCodeEsperado) {
            $mock = new MockHandler([
                new Response($statusCode, ['Content-Type' => 'application/json'], json_encode([
                    'error' => ['code' => $statusCode, 'message' => "Fallo $statusCode"],
                ])),
            ]);
            $client = new Client(['handler' => HandlerStack::create($mock)]);

            $proveedor = new GeminiPedidoOcrProvider(
                configuracion: ['enabled' => true, 'api_key' => 'key-test'],
                httpClient: $client,
            );

            try {
                $proveedor->extraer($this->imagen, 'image/jpeg');
                $this->fail("Esperaba excepción para HTTP $statusCode");
            } catch (OcrException $e) {
                $this->assertSame($errorCodeEsperado, $e->errorCode());
                $this->assertTrue($e->esReintentable(), "HTTP $statusCode debe ser reintentable.");
            }
        }

        // Caso de corte de red ConnectException
        $mockRed = new MockHandler([
            new ConnectException('Connection refused', new Request('POST', 'https://test.com')),
        ]);
        $clientRed = new Client(['handler' => HandlerStack::create($mockRed)]);

        $proveedorRed = new GeminiPedidoOcrProvider(
            configuracion: ['enabled' => true, 'api_key' => 'key-test'],
            httpClient: $clientRed,
        );

        try {
            $proveedorRed->extraer($this->imagen, 'image/jpeg');
            $this->fail('Esperaba excepción por ConnectException');
        } catch (OcrException $e) {
            $this->assertSame('red_transitoria', $e->errorCode());
            $this->assertTrue($e->esReintentable());
        }
    }

    public function test_respuesta_vacia_devuelve_estructura_cruda_vacia(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'candidates' => [],
            ])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $proveedor = new GeminiPedidoOcrProvider(
            configuracion: ['enabled' => true, 'api_key' => 'key-test'],
            httpClient: $client,
        );

        $resultado = $proveedor->extraer($this->imagen, 'image/jpeg');
        $this->assertSame('gemini', $resultado['provider']);
        $this->assertSame('', $resultado['full_text']);
        $this->assertSame([], $resultado['pages'][0]['rows']);
    }

    public function test_nombre_y_version(): void
    {
        $proveedor = new GeminiPedidoOcrProvider;
        $this->assertSame('gemini', $proveedor->nombre());
        $this->assertNotNull($proveedor->version());
    }
}
