<?php

namespace App\Services\Inventory\Orders\Ocr;

use Closure;
use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Adaptador real de Google Cloud Vision (U12, decisión D2).
 *
 * Envía la imagen a `POST {endpoint}/v1/images:annotate` con el feature
 * `DOCUMENT_TEXT_DETECTION` y convierte la respuesta a la estructura cruda
 * neutral mediante `VisionResponseBridge`, que luego consume
 * `SiigoScreenshotMapper` sin cambios.
 *
 * Autenticación: cuenta de servicio OAuth2 con alcance `cloud-vision` vía
 * `google/auth` 1.x (`ServiceAccountCredentials::fetchAuthToken()`), ya
 * presente transitivamente en vendor — por eso NO se agregó
 * `google/cloud-vision` ni se tocó composer.json/lock. No existe fallback
 * JWT artesanal: la API instalada es suficiente (verificado en U12).
 *
 * Puerta cerrada por defecto: si `inventory_orders.ocr.google.enabled` no es
 * true o no hay un archivo de credenciales legible, falla con el error
 * terminal `proveedor_no_habilitado` SIN ningún intento de red.
 *
 * Taxonomía de errores (la consume PedidoOcrService para reintentos):
 * - Terminal (sin reintento): `proveedor_no_habilitado`,
 *   `credenciales_invalidas`, `credenciales_rechazadas` (401/403),
 *   `imagen_ilegible`, `imagen_rechazada` (400), `solicitud_rechazada`
 *   (otros 4xx), `respuesta_invalida`, `respuesta_sin_geometria`,
 *   `autenticacion_fallida`.
 * - Reintentable (429/5xx/timeout/red): `limite_excedido`,
 *   `error_transitorio_vision`, `timeout_vision`, `red_transitoria`,
 *   `autenticacion_transitoria`.
 * - Respuesta sin texto (`textAnnotations` vacío): NO es error; devuelve una
 *   estructura cruda vacía y el servicio decide `requiere_revision`. Jamás se
 *   inventan datos.
 *
 * Secretos: el JSON de la cuenta de servicio se lee solo del path
 * server-side de config, nunca se registra (ni siquiera parcial), nunca
 * viaja en excepciones ni respuestas, y los bytes de la imagen solo salen
 * hacia Vision en el cuerpo HTTPS. Ver `resumenSeguroParaRegistro()`.
 */
final class GoogleVisionPedidoOcrProvider implements PedidoOcrProvider
{
    public const PROVIDER_NAME = 'google';

    public const PROVIDER_VERSION = 'google-vision-document-text-1.0.0';

    public const ALCANCE_VISION = 'https://www.googleapis.com/auth/cloud-vision';

    public const ENDPOINT_POR_DEFECTO = 'https://vision.googleapis.com/v1/images:annotate';

    /**
     * @param  array<string, mixed>|null  $configuracion  Override de
     *     `inventory_orders.ocr.google` (tests). Null = leer de config().
     * @param  Closure|null  $obtenerToken  `fn (array $credenciales): string`
     *     que devuelve el access token. Null = OAuth2 real vía google/auth.
     *     Todo el HTTP de tests pasa por aquí + `$httpClient`: sin red real.
     */
    public function __construct(
        private readonly ?array $configuracion = null,
        private readonly ?Closure $obtenerToken = null,
        private readonly ?Client $httpClient = null,
        private readonly ?VisionResponseBridge $puente = null,
    ) {}

    public function extraer(string $rutaAbsoluta, string $mimeType, array $contexto = []): array
    {
        $config = $this->configuracion ?? (array) config('inventory_orders.ocr.google', []);

        if (($config['enabled'] ?? false) !== true) {
            throw new OcrProveedorNoHabilitadoException(
                'proveedor_no_habilitado: el adaptador de Google Cloud Vision no está habilitado '
                .'(inventory_orders.ocr.google.enabled). No se realizó ninguna llamada de red.',
            );
        }

        $rutaCredenciales = is_string($config['credentials_path'] ?? null)
            ? trim((string) $config['credentials_path'])
            : '';

        if ($rutaCredenciales === '' || ! is_file($rutaCredenciales) || ! is_readable($rutaCredenciales)) {
            throw new OcrProveedorNoHabilitadoException(
                'proveedor_no_habilitado: proveedor google seleccionado pero sin archivo de credenciales '
                .'legible (GOOGLE_APPLICATION_CREDENTIALS). No se realizó ninguna llamada de red.',
            );
        }

        $credenciales = $this->leerCredenciales($rutaCredenciales);
        $bytes = $this->leerImagen($rutaAbsoluta);

        $timeout = isset($config['timeout']) && (int) $config['timeout'] > 0
            ? (int) $config['timeout']
            : 60;

        Log::debug('pedido_ocr.google.solicitud_enviada', $this->resumenSeguroParaRegistro(
            $mimeType,
            strlen($bytes),
            $timeout,
            is_string($config['project_id'] ?? null) ? (string) $config['project_id'] : null,
        ));

        $token = $this->resolverToken($credenciales);
        $respuesta = $this->llamarVision($bytes, $token, $config, $timeout);

        return $this->convertirRespuesta($respuesta);
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
     * Contexto de log sin material sensible: solo longitudes y metadatos
     * operativos. Nunca incluye bytes de imagen, base64, tokens ni claves.
     *
     * @return array<string, mixed>
     */
    public function resumenSeguroParaRegistro(string $mimeType, int $bytesImagen, int $timeout, ?string $projectId): array
    {
        return [
            'proveedor' => self::PROVIDER_NAME,
            'mime' => $mimeType,
            'bytes_imagen' => $bytesImagen,
            'timeout_s' => $timeout,
            'proyecto' => $projectId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leerCredenciales(string $ruta): array
    {
        $contenido = file_get_contents($ruta);

        if ($contenido === false || trim($contenido) === '') {
            throw OcrException::terminal('credenciales_invalidas', 'El archivo de credenciales de Google está vacío o no se pudo leer.');
        }

        try {
            $datos = json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw OcrException::terminal('credenciales_invalidas', 'El archivo de credenciales de Google no es un JSON válido.');
        }

        if (! is_array($datos)
            || ! isset($datos['client_email'], $datos['private_key'])
            || ! is_string($datos['client_email'])
            || ! is_string($datos['private_key'])
            || $datos['client_email'] === ''
            || $datos['private_key'] === '') {
            throw OcrException::terminal(
                'credenciales_invalidas',
                'El archivo de credenciales de Google no es una cuenta de servicio válida (faltan client_email o private_key).',
            );
        }

        return $datos;
    }

    private function leerImagen(string $rutaAbsoluta): string
    {
        if ($rutaAbsoluta === '' || ! is_file($rutaAbsoluta) || ! is_readable($rutaAbsoluta)) {
            throw OcrException::terminal('imagen_ilegible', 'La imagen a procesar no existe o no es legible.');
        }

        $bytes = file_get_contents($rutaAbsoluta);

        if ($bytes === false || $bytes === '') {
            throw OcrException::terminal('imagen_ilegible', 'La imagen a procesar está vacía o no se pudo leer.');
        }

        return $bytes;
    }

    /**
     * @param  array<string, mixed>  $credenciales
     */
    private function resolverToken(array $credenciales): string
    {
        if ($this->obtenerToken !== null) {
            try {
                $token = ($this->obtenerToken)($credenciales);
            } catch (OcrException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw $this->clasificarFalloAutenticacion($exception);
            }

            if (! is_string($token) || $token === '') {
                throw OcrException::terminal('autenticacion_fallida', 'El proveedor de token no devolvió un access token válido.');
            }

            return $token;
        }

        try {
            $oauth = new ServiceAccountCredentials(self::ALCANCE_VISION, $credenciales);
            $resultado = $oauth->fetchAuthToken();
        } catch (Throwable $exception) {
            throw $this->clasificarFalloAutenticacion($exception);
        }

        $token = is_array($resultado) ? ($resultado['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw OcrException::terminal('autenticacion_fallida', 'Google no devolvió un access token para la cuenta de servicio.');
        }

        return $token;
    }

    private function clasificarFalloAutenticacion(Throwable $exception): OcrException
    {
        $mensaje = strtolower($exception->getMessage().' '.$exception::class);

        if (preg_match('/invalid_grant|invalid_client|unauthorized|forbidden|401|403|invalid key| El archivo|openssl|parse/i', $mensaje) === 1
            && preg_match('/curl|could not resolve|timed out|timeout|503|500|502|504|connection/i', $mensaje) !== 1) {
            return OcrException::terminal(
                'credenciales_invalidas',
                'Las credenciales de Google fueron rechazadas o son inválidas; no se reintenta.',
                $exception,
            );
        }

        return OcrException::reintentable(
            'autenticacion_transitoria',
            'Fallo transitorio obteniendo el token de Google; se puede reintentar.',
            $exception,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function llamarVision(string $bytes, string $token, array $config, int $timeout): array
    {
        $endpoint = is_string($config['endpoint'] ?? null) && trim((string) $config['endpoint']) !== ''
            ? trim((string) $config['endpoint'])
            : self::ENDPOINT_POR_DEFECTO;

        $cliente = $this->httpClient ?? new Client(['timeout' => $timeout]);

        try {
            $respuesta = $cliente->post($endpoint, [
                'timeout' => $timeout,
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'requests' => [
                        [
                            'image' => ['content' => base64_encode($bytes)],
                            'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']],
                        ],
                    ],
                ],
            ]);
        } catch (ConnectException $exception) {
            throw OcrException::reintentable('red_transitoria', 'No se pudo conectar con Cloud Vision; se puede reintentar.', $exception);
        } catch (TransferException $exception) {
            if ($exception instanceof RequestException) {
                $codigo = $exception->getResponse() !== null ? $exception->getResponse()->getStatusCode() : null;

                if ($codigo !== null) {
                    throw $this->clasificarEstadoHttp($codigo, $exception);
                }
            }

            throw OcrException::reintentable('timeout_vision', 'La llamada a Cloud Vision agotó el tiempo o falló la transferencia; se puede reintentar.', $exception);
        } catch (Throwable $exception) {
            throw OcrException::reintentable('red_transitoria', 'Fallo de red llamando a Cloud Vision; se puede reintentar.', $exception);
        }

        $cuerpo = (string) $respuesta->getBody();

        try {
            $datos = json_decode($cuerpo, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw OcrException::terminal('respuesta_invalida', 'Cloud Vision devolvió una respuesta que no es JSON válido.', $exception);
        }

        if (! is_array($datos)) {
            throw OcrException::terminal('respuesta_invalida', 'Cloud Vision devolvió una respuesta con forma inesperada.');
        }

        return $datos;
    }

    private function clasificarEstadoHttp(int $codigo, Throwable $anterior): OcrException
    {
        if ($codigo === 429) {
            return OcrException::reintentable('limite_excedido', 'Cloud Vision devolvió 429 (cuota/límite excedido); se puede reintentar con backoff.', $anterior);
        }

        if ($codigo >= 500) {
            return OcrException::reintentable('error_transitorio_vision', sprintf('Cloud Vision devolvió %d; se puede reintentar.', $codigo), $anterior);
        }

        if ($codigo === 400) {
            return OcrException::terminal('imagen_rechazada', 'Cloud Vision rechazó la imagen (400); no se reintenta.', $anterior);
        }

        if ($codigo === 401 || $codigo === 403) {
            return OcrException::terminal('credenciales_rechazadas', sprintf('Cloud Vision devolvió %d: credenciales sin permiso; no se reintenta.', $codigo), $anterior);
        }

        return OcrException::terminal('solicitud_rechazada', sprintf('Cloud Vision devolvió %d; no se reintenta.', $codigo), $anterior);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function convertirRespuesta(array $datos): array
    {
        $primera = is_array($datos['responses'] ?? null) ? ($datos['responses'][0] ?? null) : null;

        if (! is_array($primera)) {
            throw OcrException::terminal('respuesta_invalida', 'Cloud Vision devolvió una respuesta sin objeto de documento.');
        }

        if (isset($primera['error']) && is_array($primera['error'])) {
            $codigo = isset($primera['error']['code']) && is_numeric($primera['error']['code'])
                ? (int) $primera['error']['code']
                : 0;

            throw $this->clasificarEstadoHttp($codigo > 0 ? $codigo : 400, new \RuntimeException(
                'Cloud Vision informó un error en la respuesta del documento.'
            ));
        }

        $anotaciones = $primera['textAnnotations'] ?? null;
        $tieneTexto = is_array($anotaciones) && $anotaciones !== [];

        if (! isset($primera['fullTextAnnotation']) || ! is_array($primera['fullTextAnnotation'])) {
            if (! $tieneTexto) {
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

            throw OcrException::terminal(
                'respuesta_sin_geometria',
                'Cloud Vision detectó texto pero no devolvió geometría de documento (fullTextAnnotation).',
            );
        }

        $puente = $this->puente ?? new VisionResponseBridge();
        $crudo = $puente->convertir($primera);

        return [
            'provider' => self::PROVIDER_NAME,
            'provider_version' => self::PROVIDER_VERSION,
            'full_text' => $crudo['full_text'],
            'pages' => $crudo['pages'],
        ];
    }
}
