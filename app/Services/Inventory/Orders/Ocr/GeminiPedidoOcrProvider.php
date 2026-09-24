<?php

namespace App\Services\Inventory\Orders\Ocr;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Adaptador alternativo de Google Gemini 2.5 Flash para OCR de pedidos de inventario.
 *
 * Envía la imagen a `POST {endpoint}/{model}:generateContent?key={apiKey}` con
 * `response_mime_type: application/json` y `temperature: 0.0`. Convierte la
 * respuesta estructurada a la matriz neutral de páginas/filas/celdas que consume
 * SiigoScreenshotMapper sin cambios, preservando la validación matemática estricta.
 *
 * Autenticación: clave de API directa (`GEMINI_API_KEY`), ideal para entornos
 * ágiles sin la sobrecarga de cuentas de servicio de Google Cloud.
 *
 * Puerta cerrada por defecto: si `inventory_orders.ocr.gemini.enabled` no es
 * true o no hay clave configurada, falla de inmediato con el error terminal
 * `proveedor_no_habilitado` SIN llamadas de red.
 */
final class GeminiPedidoOcrProvider implements PedidoOcrProvider
{
    public const PROVIDER_NAME = 'gemini';

    public const PROVIDER_VERSION = 'gemini-2.5-flash-structured-1.0.0';

    public const ENDPOINT_POR_DEFECTO = 'https://generativelanguage.googleapis.com/v1beta/models';

    public const MODELO_POR_DEFECTO = 'gemini-2.5-flash';

    private const PROMPT_TRANSCRIPCION = <<<'PROMPT'
Eres un sistema experto en OCR para transcribir documentos y tablas contables de Siigo.
Tu objetivo es transcribir fielmente todo el texto visible estructurado en filas y celdas de tabla.

REGLAS DE EXTRACCIÓN:
1. Extrae cada fila visual del documento de arriba hacia abajo.
2. Cada fila contiene una lista de celdas ordenadas de izquierda a derecha.
3. Transcribe el texto EXACTO tal como aparece (mantén mayúsculas, signos, números y puntuación).
4. No omitas filas de cabecera ni filas de totales.
5. No inventes filas, no combines columnas separadas.
6. Si una celda está vacía, coloca texto vacío "".

Devuelve EXCLUSIVAMENTE un JSON válido con esta estructura:
{
  "filas": [
    ["Señor(es):", "NOMBRE PROVEEDOR"],
    ["CÓDIGO", "DESCRIPCIÓN", "CANT.", "VALOR UNIT.", "VALOR TOTAL"],
    ["SKU-001", "DESCRIPCION DEL ITEM", "5.00", "10,000.00", "50,000.00"]
  ]
}

VARIANTE DOCUMENTO SOPORTE (pantalla Siigo con pastillas y tabla extendida):
7. La cabecera puede ser "# | Tipo | Producto | Descripción | Bodega | Cant |
   Valor Unitario | Descuento | Impuesto Cargo | Impuesto Retención |
   Valor Total". En esta variante la columna `Producto` contiene el SKU
   (código del ítem) y la columna `Cant` la cantidad.
8. La descripción del producto puede ocupar 2 líneas visuales: transcribe
   ambas líneas dentro de la MISMA celda separadas por "\n".
9. El proveedor puede venir en pastillas superiores ("Proveedores: NOMBRE",
   "Contacto: NOMBRE"): transcribe cada pastilla como su propia fila de dos
   celdas, p. ej. ["Proveedores:", "NOMBRE"], sin mezclarla con otras filas.
PROMPT;

    /**
     * @param  array<string, mixed>|null  $configuracion  Override de config (tests).
     * @param  Client|null  $httpClient  Cliente HTTP inyectable para pruebas offline.
     */
    public function __construct(
        private readonly ?array $configuracion = null,
        private readonly ?Client $httpClient = null,
    ) {}

    public function extraer(string $rutaAbsoluta, string $mimeType, array $contexto = []): array
    {
        $config = $this->configuracion ?? (array) config('inventory_orders.ocr.gemini', []);

        if (($config['enabled'] ?? false) !== true) {
            throw new OcrProveedorNoHabilitadoException(
                'proveedor_no_habilitado: el adaptador de Google Gemini no está habilitado '
                .'(inventory_orders.ocr.gemini.enabled). No se realizó ninguna llamada de red.',
            );
        }

        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new OcrProveedorNoHabilitadoException(
                'proveedor_no_habilitado: proveedor gemini seleccionado pero sin GEMINI_API_KEY '
                .'configurada. No se realizó ninguna llamada de red.',
            );
        }

        if (! file_exists($rutaAbsoluta) || ! is_readable($rutaAbsoluta)) {
            throw OcrException::terminal(
                'imagen_ilegible',
                sprintf('No se puede leer la imagen en %s.', $rutaAbsoluta),
            );
        }

        $bytes = (string) file_get_contents($rutaAbsoluta);
        if ($bytes === '') {
            throw OcrException::terminal(
                'imagen_ilegible',
                'El archivo de imagen está vacío (0 bytes).',
            );
        }

        $endpoint = rtrim((string) ($config['endpoint'] ?? self::ENDPOINT_POR_DEFECTO), '/');
        $model = (string) ($config['model'] ?? self::MODELO_POR_DEFECTO);
        $timeout = (int) ($config['timeout'] ?? 60);

        $url = sprintf('%s/%s:generateContent?key=%s', $endpoint, $model, urlencode($apiKey));

        Log::info('pedido_ocr.gemini.solicitud', [
            'modelo' => $model,
            'bytes' => strlen($bytes),
            'mime' => $mimeType,
            'timeout' => $timeout,
        ]);

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => self::PROMPT_TRANSCRIPCION],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => base64_encode($bytes),
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.0,
                'response_mime_type' => 'application/json',
            ],
        ];

        $verifySsl = (bool) ($config['verify_ssl'] ?? true);
        $client = $this->httpClient ?? new Client(['timeout' => $timeout, 'verify' => $verifySsl]);

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
        } catch (ConnectException $connectException) {
            throw OcrException::reintentable(
                'red_transitoria',
                'Fallo de conexión al conectar con la API de Google Gemini: '.$connectException->getMessage(),
                $connectException,
            );
        } catch (RequestException $requestException) {
            $statusCode = $requestException->getResponse()?->getStatusCode();
            $body = (string) $requestException->getResponse()?->getBody();

            $errorParsed = $this->extraerMensajeErrorGoogle($body);
            $mensaje = sprintf('Error HTTP %s de Gemini API: %s', $statusCode ?? 0, $errorParsed ?? $requestException->getMessage());

            if ($statusCode === 400) {
                throw OcrException::terminal('imagen_rechazada', $mensaje, $requestException);
            }

            if ($statusCode === 401 || $statusCode === 403) {
                throw OcrException::terminal('credenciales_rechazadas', $mensaje, $requestException);
            }

            if ($statusCode === 429) {
                throw OcrException::reintentable('limite_excedido', $mensaje, $requestException);
            }

            if ($statusCode !== null && $statusCode >= 500) {
                throw OcrException::reintentable('error_transitorio_gemini', $mensaje, $requestException);
            }

            if ($statusCode !== null && $statusCode >= 400) {
                throw OcrException::terminal('solicitud_rechazada', $mensaje, $requestException);
            }

            throw OcrException::reintentable('red_transitoria', $mensaje, $requestException);
        } catch (TransferException $transferException) {
            throw OcrException::reintentable(
                'timeout_gemini',
                'Timeout o corte de transferencia con la API de Google Gemini: '.$transferException->getMessage(),
                $transferException,
            );
        } catch (JsonException $jsonException) {
            throw OcrException::terminal(
                'solicitud_rechazada',
                'No se pudo codificar el payload JSON de solicitud para Gemini: '.$jsonException->getMessage(),
                $jsonException,
            );
        } catch (Throwable $throwable) {
            throw OcrException::reintentable(
                'error_transitorio_gemini',
                'Error inesperado al invocar Google Gemini: '.$throwable->getMessage(),
                $throwable,
            );
        }

        $cuerpo = (string) $response->getBody();

        return $this->parsearRespuesta($cuerpo);
    }

    public function nombre(): string
    {
        return self::PROVIDER_NAME;
    }

    public function version(): ?string
    {
        return self::PROVIDER_VERSION;
    }

    /**
     * Parsea la respuesta de Gemini y la convierte en la matriz neutral.
     *
     * @return array<string, mixed>
     */
    private function parsearRespuesta(string $cuerpo): array
    {
        try {
            $data = json_decode($cuerpo, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw OcrException::terminal('respuesta_invalida', 'La respuesta de Gemini no es un JSON válido: '.$e->getMessage(), $e);
        }

        if (isset($data['error'])) {
            $code = (int) ($data['error']['code'] ?? 0);
            $msg = (string) ($data['error']['message'] ?? 'Error desconocido de Gemini');
            $esReintentable = ($code === 429 || $code >= 500);

            throw $esReintentable
                ? OcrException::reintentable('limite_excedido', sprintf('Gemini devolvió error en cuerpo: [%s] %s', $code, $msg))
                : OcrException::terminal('solicitud_rechazada', sprintf('Gemini devolvió error en cuerpo: [%s] %s', $code, $msg));
        }

        $candidatos = $data['candidates'] ?? [];
        if (empty($candidatos)) {
            // Respuesta vacía sin candidatos
            return $this->estructuraVacia();
        }

        $texto = $candidatos[0]['content']['parts'][0]['text'] ?? '';
        if (trim($texto) === '') {
            return $this->estructuraVacia();
        }

        $filas = $this->extraerFilasDesdeTexto($texto);

        return $this->construirEstructuraNeutral($filas);
    }

    /**
     * @return list<list<string>>
     */
    private function extraerFilasDesdeTexto(string $texto): array
    {
        $parsed = json_decode($texto, true);
        if (! is_array($parsed)) {
            if (preg_match('/\{[\s\S]*\}/', $texto, $matches)) {
                $parsed = json_decode($matches[0], true);
            }
        }

        if (! is_array($parsed) || ! isset($parsed['filas']) || ! is_array($parsed['filas'])) {
            // Fallback: si no devolvió JSON con 'filas', separar por líneas de texto
            $lineas = explode("\n", str_replace(["\r\n", "\r"], "\n", $texto));
            $resultado = [];
            foreach ($lineas as $linea) {
                $trim = trim($linea);
                if ($trim !== '') {
                    $resultado[] = preg_split('/\t+|\s{2,}/', $trim) ?: [$trim];
                }
            }

            return $resultado;
        }

        $resultado = [];
        foreach ($parsed['filas'] as $fila) {
            if (is_array($fila)) {
                $resultado[] = array_map(fn ($c) => (string) $c, $fila);
            }
        }

        return $resultado;
    }

    /**
     * @param  list<list<string>>  $filas
     * @return array<string, mixed>
     */
    private function construirEstructuraNeutral(array $filas): array
    {
        $rows = [];
        $fullTextLines = [];

        foreach ($filas as $filaIndex => $celdas) {
            $rowNumber = $filaIndex + 1;
            $rowCells = [];
            $rowTexts = [];

            foreach ($celdas as $colIndex => $celdaTexto) {
                $colNumber = $colIndex + 1;
                $cleanText = trim((string) $celdaTexto);

                $rowCells[] = [
                    'column' => $colNumber,
                    'text' => (string) $celdaTexto,
                    'confidence' => 0.95,
                    'bounding_box' => null,
                ];

                if ($cleanText !== '') {
                    $rowTexts[] = $cleanText;
                }
            }

            if (! empty($rowCells)) {
                $rows[] = [
                    'row' => $rowNumber,
                    'cells' => $rowCells,
                ];
            }

            if (! empty($rowTexts)) {
                $fullTextLines[] = implode(' ', $rowTexts);
            }
        }

        return [
            'provider' => self::PROVIDER_NAME,
            'provider_version' => self::PROVIDER_VERSION,
            'full_text' => implode("\n", $fullTextLines),
            'pages' => [
                [
                    'page_number' => 1,
                    'width' => null,
                    'height' => null,
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function estructuraVacia(): array
    {
        return [
            'provider' => self::PROVIDER_NAME,
            'provider_version' => self::PROVIDER_VERSION,
            'full_text' => '',
            'pages' => [
                [
                    'page_number' => 1,
                    'width' => null,
                    'height' => null,
                    'rows' => [],
                ],
            ],
        ];
    }

    private function extraerMensajeErrorGoogle(string $cuerpo): ?string
    {
        if ($cuerpo === '') {
            return null;
        }

        $decoded = json_decode($cuerpo, true);
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            return (string) $decoded['error']['message'];
        }

        return null;
    }
}
