<?php

namespace Tests\Unit\InventoryOrders;

use App\Services\Inventory\Orders\Ocr\GoogleVisionPedidoOcrProvider;
use App\Services\Inventory\Orders\Ocr\OcrException;
use App\Services\Inventory\Orders\Ocr\OcrProveedorNoHabilitadoException;
use App\Services\Inventory\Orders\Ocr\VisionResponseBridge;
use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * Adaptador Google Cloud Vision (U12).
 *
 * Cero red real: el token se inyecta por Closure y todo el HTTP pasa por un
 * Guzzle MockHandler. Las claves RSA de estas pruebas se generan en memoria
 * con openssl y nunca son credenciales reales.
 */
class GoogleVisionPedidoOcrProviderTest extends TestCase
{
    private string $imagen;

    private string $credenciales;

    /** @var list<array<string, mixed>> */
    private array $historial = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->imagen = tempnam(sys_get_temp_dir(), 'vision_img_');
        file_put_contents($this->imagen, 'bytes-falsos-de-imagen');

        $this->credenciales = tempnam(sys_get_temp_dir(), 'vision_cred_');
        file_put_contents($this->credenciales, (string) json_encode([
            'type' => 'service_account',
            'project_id' => 'proyecto-falso',
            'private_key_id' => 'id-falso',
            'private_key' => 'clave-falsa-solo-para-gating',
            'client_email' => 'falso@proyecto-falso.iam.gserviceaccount.com',
            'client_id' => '000',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
    }

    protected function tearDown(): void
    {
        foreach ([$this->imagen, $this->credenciales] as $ruta) {
            if (is_file($ruta)) {
                unlink($ruta);
            }
        }

        parent::tearDown();
    }

    public function test_deshabilitado_falla_cerrado_sin_red(): void
    {
        $proveedor = $this->proveedor(['enabled' => false], []);

        try {
            $proveedor->extraer($this->imagen, 'image/jpeg');
            $this->fail('Deshabilitado debe fallar cerrado.');
        } catch (OcrProveedorNoHabilitadoException $exception) {
            $this->assertSame('proveedor_no_habilitado', $exception->errorCode());
            $this->assertFalse($exception->esReintentable());
        }

        $this->assertCount(0, $this->historial, 'Cero intentos de red con el proveedor apagado.');
    }

    public function test_sin_archivo_de_credenciales_falla_cerrado_sin_red(): void
    {
        $proveedor = $this->proveedor([
            'enabled' => true,
            'credentials_path' => sys_get_temp_dir().'/credenciales-que-no-existen.json',
        ], []);

        try {
            $proveedor->extraer($this->imagen, 'image/jpeg');
            $this->fail('Sin credenciales debe fallar cerrado.');
        } catch (OcrProveedorNoHabilitadoException $exception) {
            $this->assertSame('proveedor_no_habilitado', $exception->errorCode());
        }

        $this->assertCount(0, $this->historial);
    }

    public function test_exito_devuelve_crudo_del_puente_con_cuerpo_correcto(): void
    {
        $proveedor = $this->proveedor($this->configHabilitada(), [
            new Response(200, [], (string) json_encode([
                'responses' => [[
                    'textAnnotations' => [['description' => 'TO000057 4.00']],
                    'fullTextAnnotation' => [
                        'pages' => [[
                            'width' => 800,
                            'height' => 600,
                            'blocks' => [[
                                'paragraphs' => [[
                                    'words' => [
                                        $this->palabra('TO000057', 40, 240, 120, 26, 0.93),
                                        $this->palabra('TORNILLO', 205, 238, 150, 26, 0.92),
                                        $this->palabra('4.00', 640, 240, 60, 26, 0.94),
                                    ],
                                ]],
                            ]],
                        ]],
                    ],
                ]],
            ])),
        ]);

        $crudo = $proveedor->extraer($this->imagen, 'image/jpeg');

        $this->assertSame('google', $crudo['provider']);
        $this->assertSame(GoogleVisionPedidoOcrProvider::PROVIDER_VERSION, $crudo['provider_version']);
        $this->assertCount(1, $crudo['pages']);
        $this->assertCount(1, $crudo['pages'][0]['rows']);
        $this->assertSame('TO000057', $crudo['pages'][0]['rows'][0]['cells'][0]['text']);

        $this->assertCount(1, $this->historial);
        $peticion = $this->historial[0]['request'];
        $this->assertSame('Bearer token-de-prueba', $peticion->getHeaderLine('Authorization'));

        $cuerpo = json_decode((string) $peticion->getBody(), true);
        $this->assertSame('DOCUMENT_TEXT_DETECTION', $cuerpo['requests'][0]['features'][0]['type']);
        $this->assertSame(base64_encode('bytes-falsos-de-imagen'), $cuerpo['requests'][0]['image']['content']);
    }

    public function test_ruta_relativa_se_resuelve_contra_base_path(): void
    {
        $relativa = 'storage/app/credenciales-relativa-probe.json';
        $absoluta = base_path($relativa);

        file_put_contents($absoluta, (string) json_encode([
            'type' => 'service_account',
            'client_email' => 'falso@proyecto-falso.iam.gserviceaccount.com',
            'private_key' => 'clave-falsa-solo-para-gating',
        ]));

        try {
            $config = $this->configHabilitada();
            $config['credentials_path'] = $relativa;

            $proveedor = $this->proveedor($config, [
                new Response(200, [], (string) json_encode(['responses' => [[]]])),
            ]);

            $crudo = $proveedor->extraer($this->imagen, 'image/jpeg');

            $this->assertSame('google', $crudo['provider']);
            $this->assertCount(1, $this->historial);
        } finally {
            if (is_file($absoluta)) {
                unlink($absoluta);
            }
        }
    }

    public function test_errores_401_403_400_son_terminales(): void
    {
        foreach ([401, 403, 400] as $estado) {
            $proveedor = $this->proveedor($this->configHabilitada(), [
                new Response($estado, [], '{"error":{"code":'.$estado.',"message":"rechazado"}}'),
            ]);

            try {
                $proveedor->extraer($this->imagen, 'image/jpeg');
                $this->fail("HTTP {$estado} debe ser terminal.");
            } catch (OcrException $exception) {
                $this->assertFalse($exception->esReintentable(), "HTTP {$estado} no se reintenta.");
                $this->assertContains($exception->errorCode(), ['credenciales_rechazadas', 'imagen_rechazada']);
            }
        }
    }

    public function test_errores_429_5xx_y_cortes_de_red_son_reintentables(): void
    {
        $casos = [
            [new Response(429, [], '{"error":{"code":429,"message":"cuota"}}'), 'limite_excedido'],
            [new Response(500, [], '{"error":{"code":500,"message":"interno"}}'), 'error_transitorio_vision'],
            [new Response(503, [], '{"error":{"code":503,"message":"no disponible"}}'), 'error_transitorio_vision'],
            [new ConnectException('timed out', new Request('POST', 'https://vision.googleapis.com/v1/images:annotate')), 'red_transitoria'],
            [new TransferException('cURL error 28: Operation timed out'), 'timeout_vision'],
        ];

        foreach ($casos as [$respuesta, $codigoEsperado]) {
            $proveedor = $this->proveedor($this->configHabilitada(), [$respuesta]);

            try {
                $proveedor->extraer($this->imagen, 'image/jpeg');
                $this->fail("{$codigoEsperado} debe ser reintentable.");
            } catch (OcrException $exception) {
                $this->assertTrue($exception->esReintentable(), "{$codigoEsperado} se reintenta.");
                $this->assertSame($codigoEsperado, $exception->errorCode());
            }
        }
    }

    public function test_error_en_respuesta_200_se_clasifica(): void
    {
        $proveedor = $this->proveedor($this->configHabilitada(), [
            new Response(200, [], (string) json_encode(['responses' => [['error' => ['code' => 429, 'message' => 'cuota']]]])),
        ]);

        try {
            $proveedor->extraer($this->imagen, 'image/jpeg');
            $this->fail('El error 429 dentro de 200 debe ser reintentable.');
        } catch (OcrException $exception) {
            $this->assertTrue($exception->esReintentable());
            $this->assertSame('limite_excedido', $exception->errorCode());
        }
    }

    public function test_respuesta_sin_texto_devuelve_crudo_vacio_sin_error(): void
    {
        $proveedor = $this->proveedor($this->configHabilitada(), [
            new Response(200, [], (string) json_encode(['responses' => [[]]])),
        ]);

        $crudo = $proveedor->extraer($this->imagen, 'image/jpeg');

        $this->assertSame('google', $crudo['provider']);
        $this->assertSame([], $crudo['pages'][0]['rows'], 'Sin texto no se inventan filas: el servicio decidirá requiere_revision.');
    }

    public function test_texto_sin_geometria_es_terminal(): void
    {
        $proveedor = $this->proveedor($this->configHabilitada(), [
            new Response(200, [], (string) json_encode(['responses' => [[
                'textAnnotations' => [['description' => 'algo sin geometría']],
            ]]])),
        ]);

        try {
            $proveedor->extraer($this->imagen, 'image/jpeg');
            $this->fail('Texto sin fullTextAnnotation debe ser terminal.');
        } catch (OcrException $exception) {
            $this->assertSame('respuesta_sin_geometria', $exception->errorCode());
            $this->assertFalse($exception->esReintentable());
        }
    }

    public function test_credenciales_malformadas_son_terminales(): void
    {
        file_put_contents($this->credenciales, '{json roto');

        $proveedor = $this->proveedor($this->configHabilitada(), []);

        try {
            $proveedor->extraer($this->imagen, 'image/jpeg');
            $this->fail('JSON de credenciales roto debe ser terminal.');
        } catch (OcrException $exception) {
            $this->assertSame('credenciales_invalidas', $exception->errorCode());
            $this->assertFalse($exception->esReintentable());
        }

        $this->assertCount(0, $this->historial);
    }

    public function test_registro_redactado_nunca_lleva_secretos_ni_imagen(): void
    {
        $proveedor = new GoogleVisionPedidoOcrProvider;

        $secretoImagen = base64_encode('bytes-falsos-de-imagen');
        $resumen = $proveedor->resumenSeguroParaRegistro('image/jpeg', 1234, 60, 'proyecto-falso');

        $plano = (string) json_encode($resumen);

        $this->assertStringNotContainsString($secretoImagen, $plano);
        $this->assertStringNotContainsString('private_key', $plano);
        $this->assertStringNotContainsString('token-de-prueba', $plano);
        $this->assertSame('image/jpeg', $resumen['mime']);
        $this->assertSame(1234, $resumen['bytes_imagen']);
    }

    public function test_google_auth_acepta_clave_generada_en_memoria_y_firma_local(): void
    {
        [$recurso, $configSsl] = $this->generarClaveRsaPrueba();

        if ($recurso === null) {
            $this->markTestSkipped('openssl no pudo generar una clave RSA en este entorno (falta openssl.cnf).');
        }

        $exportada = '';
        $opciones = $configSsl !== null ? ['config' => $configSsl] : null;
        $this->assertTrue(openssl_pkey_export($recurso, $exportada, null, $opciones));
        $this->assertStringContainsString('PRIVATE KEY', $exportada);

        $credenciales = [
            'type' => 'service_account',
            'client_email' => 'prueba@proyecto-falso.iam.gserviceaccount.com',
            'private_key' => $exportada,
        ];

        // Construcción + firma puramente locales: sin red (fetchAuthToken NUNCA se llama aquí).
        $oauth = new ServiceAccountCredentials(GoogleVisionPedidoOcrProvider::ALCANCE_VISION, $credenciales);
        $firma = $oauth->signBlob('contenido-de-prueba');

        $this->assertIsString($firma);
        $this->assertNotSame('', $firma);
    }

    public function test_nombre_y_version_del_proveedor(): void
    {
        $proveedor = new GoogleVisionPedidoOcrProvider;

        $this->assertSame('google', $proveedor->nombre());
        $this->assertSame(GoogleVisionPedidoOcrProvider::PROVIDER_VERSION, $proveedor->version());
    }

    /**
     * Genera una RSA de prueba en memoria. En Windows el openssl.cnf a veces
     * no está en el path de OpenSSL: se prueba la config por defecto, la de
     * OPENSSL_CONF y ubicaciones conocidas de scoop/laragon.
     *
     * @return array{0: \OpenSSLAsymmetricKey|null, 1: string|null}
     */
    private function generarClaveRsaPrueba(): array
    {
        $candidatos = [null];

        $env = getenv('OPENSSL_CONF');
        if (is_string($env) && $env !== '') {
            $candidatos[] = $env;
        }

        foreach (glob('C:/Users/*/scoop/apps/php/*/extras/ssl/openssl.cnf') ?: [] as $ruta) {
            $candidatos[] = $ruta;
        }

        foreach (glob('C:/laragon/bin/php/*/extras/ssl/openssl.cnf') ?: [] as $ruta) {
            $candidatos[] = $ruta;
        }

        foreach ($candidatos as $config) {
            $args = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

            if ($config !== null) {
                $args['config'] = $config;
            }

            $recurso = openssl_pkey_new($args);

            if ($recurso !== false) {
                return [$recurso, $config];
            }
        }

        return [null, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function configHabilitada(): array
    {
        return [
            'enabled' => true,
            'project_id' => 'proyecto-falso',
            'credentials_path' => $this->credenciales,
            'timeout' => 10,
            'endpoint' => 'https://vision.googleapis.com/v1/images:annotate',
        ];
    }

    /**
     * @param  list<object>  $respuestas
     */
    private function proveedor(array $config, array $respuestas): GoogleVisionPedidoOcrProvider
    {
        $mock = new MockHandler($respuestas);
        $pila = HandlerStack::create($mock);
        $pila->push(Middleware::history($this->historial));

        return new GoogleVisionPedidoOcrProvider(
            $config,
            static fn (array $credenciales): string => 'token-de-prueba',
            new Client(['handler' => $pila]),
            new VisionResponseBridge,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function palabra(string $texto, int $x, int $y, int $w, int $h, float $conf): array
    {
        $longitud = (int) mb_strlen($texto);
        $simbolos = [];

        for ($i = 0; $i < $longitud; $i++) {
            $sx = (int) round($x + $i * $w / $longitud);
            $ex = (int) round($x + ($i + 1) * $w / $longitud);
            $simbolos[] = [
                'text' => mb_substr($texto, $i, 1),
                'confidence' => $conf,
                'boundingBox' => ['vertices' => [
                    ['x' => $sx, 'y' => $y],
                    ['x' => $ex, 'y' => $y],
                    ['x' => $ex, 'y' => $y + $h],
                    ['x' => $sx, 'y' => $y + $h],
                ]],
            ];
        }

        return [
            'boundingBox' => ['vertices' => [
                ['x' => $x, 'y' => $y],
                ['x' => $x + $w, 'y' => $y],
                ['x' => $x + $w, 'y' => $y + $h],
                ['x' => $x, 'y' => $y + $h],
            ]],
            'symbols' => $simbolos,
            'confidence' => $conf,
        ];
    }
}
