<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Illuminate\Support\Facades\Log;
use Exception;

class FirebaseService
{
    /**
     * Enviar una notificación Push mediante Firebase Cloud Messaging (FCM)
     *
     * @param string|null $deviceToken Token FCM del dispositivo destino.
     * @param string $title Título de la notificación.
     * @param string $body Cuerpo o mensaje de la notificación.
     * @param array $data Datos adicionales (ej: ID de recurso relacionado).
     * @return bool True si se envió correctamente, False en caso de error o token nulo.
     */
    public static function sendPush(?string $deviceToken, string $title, string $body, array $data = []): bool
    {
        if (empty($deviceToken)) {
            return false;
        }

        try {
            $factory = new Factory();
            $base64Credentials = env('FIREBASE_CREDENTIALS_BASE64');

            if (!empty($base64Credentials)) {
                $credentialsArray = json_decode(base64_decode($base64Credentials), true);
                if (!$credentialsArray) {
                    Log::error("FirebaseService Error: FIREBASE_CREDENTIALS_BASE64 no es un JSON o Base64 valido.");
                    return false;
                }
                $factory = $factory->withServiceAccount($credentialsArray);
            } else {
                // Fallback a archivo (recomendado: storage/app/secret/ para aislar las llaves de forma segura)
                $credentialsPath = base_path(env('FIREBASE_CREDENTIALS', 'storage/app/secret/firebase_credentials.json'));

                if (!file_exists($credentialsPath)) {
                    Log::warning("FirebaseService: No se encontraron credenciales en FIREBASE_CREDENTIALS_BASE64 ni el archivo en {$credentialsPath}");
                    return false;
                }
                $factory = $factory->withServiceAccount($credentialsPath);
            }

            $messaging = $factory->createMessaging();

            $message = CloudMessage::withTarget('token', $deviceToken)
                ->withNotification(Notification::create($title, $body))
                ->withData($data);

            $messaging->send($message);
            
            return true;
        } catch (Exception $e) {
            // Es vital capturar la excepción en hosting compartido para no quebrar el cron
            Log::error("FirebaseService Error: Fallo al enviar push a token {$deviceToken}. Detalle: " . $e->getMessage());
            return false;
        }
    }
}
