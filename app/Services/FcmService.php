<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FcmService
{
    /**
     * Envía una notificación push a través de Firebase v1 API de forma nativa sin dependencias pesadas.
     */
    public static function send(string $token, string $title, string $body, array $data = []): bool
    {
        try {
            $projectId = self::getProjectId();
            if (!$projectId) {
                Log::warning('FcmService: No se encontró project_id. Asegúrate de colocar firebase-service-account.json');
                return false;
            }

            $accessToken = self::getAccessToken();
            if (!$accessToken) {
                Log::error('FcmService: No se pudo obtener el Access Token de Google.');
                return false;
            }

            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

            // Normalizar todos los valores de $data a string (FCM v1 exige strings)
            $formattedData = [];
            foreach ($data as $key => $value) {
                $formattedData[(string)$key] = (string)$value;
            }

            $messagePayload = [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'android' => [
                    'notification' => [
                        'sound' => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ]
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ]
                    ]
                ]
            ];

            if (!empty($formattedData)) {
                $messagePayload['data'] = $formattedData;
            }

            $payload = [
                'message' => $messagePayload
            ];

            $client = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json']);

            if (app()->environment('local')) {
                $client = $client->withoutVerifying();
            }

            $response = $client->post($url, $payload);

            if ($response->successful()) {
                Log::info("FcmService: Notificación enviada con éxito a {$token}");
                return true;
            }

            Log::error("FcmService: Error enviando push: " . $response->body());
            return false;
        } catch (\Exception $e) {
            Log::error("FcmService: Excepción al enviar push: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Lee el ID del proyecto desde el archivo JSON de cuenta de servicio.
     */
    private static function getProjectId(): ?string
    {
        $creds = self::getCredentials();
        return $creds['project_id'] ?? null;
    }

    /**
     * Obtiene el token de acceso OAuth2 y lo cachea por 50 minutos.
     */
    private static function getAccessToken(): ?string
    {
        return Cache::remember('fcm_google_access_token', 3000, function () {
            $creds = self::getCredentials();
            if (!$creds) {
                return null;
            }

            // Crear JWT Assertion para intercambiar por token de acceso de Google
            $jwt = self::generateJwt($creds);
            if (!$jwt) {
                return null;
            }

            $client = Http::asForm();
            if (app()->environment('local')) {
                $client = $client->withoutVerifying();
            }

            $response = $client->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->successful() && isset($response['access_token'])) {
                return $response['access_token'];
            }

            Log::error("FcmService: Error de intercambio JWT: " . $response->body());
            return null;
        });
    }

    /**
     * Genera un JWT Assertion firmado localmente usando OpenSSL nativo.
     */
    private static function generateJwt(array $creds): ?string
    {
        $privateKey = $creds['private_key'] ?? null;
        $clientEmail = $creds['client_email'] ?? null;

        if (!$privateKey || !$clientEmail) {
            return null;
        }

        $now = time();
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);

        $claim = json_encode([
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ]);

        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlClaim = self::base64UrlEncode($claim);

        $signatureInput = "{$base64UrlHeader}.{$base64UrlClaim}";
        $signature = '';

        if (!openssl_sign($signatureInput, $signature, $privateKey, 'SHA256')) {
            Log::error("FcmService: Error al firmar JWT con OpenSSL.");
            return null;
        }

        $base64UrlSignature = self::base64UrlEncode($signature);

        return "{$signatureInput}.{$base64UrlSignature}";
    }

    /**
     * Carga y parsea el archivo de credenciales JSON de Firebase.
     */
    private static function getCredentials(): ?array
    {
        $path = storage_path('app/firebase-service-account.json');
        if (!file_exists($path)) {
            return null;
        }

        try {
            return json_decode(file_get_contents($path), true);
        } catch (\Exception $e) {
            Log::error("FcmService: Error leyendo credentials json: " . $e->getMessage());
            return null;
        }
    }

    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}
