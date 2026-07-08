<?php

namespace App\Observers;

use App\Models\Notificacion;
use App\Events\NotificationSent;

class NotificacionObserver
{
    /**
     * Handle the Notificacion "created" event.
     */
    public function created(Notificacion $notificacion): void
    {
        // Broadcast en tiempo real (Pusher) para la web y app abierta
        broadcast(new NotificationSent($notificacion))->toOthers();

        // Enviar notificación Push (FCM) para dispositivos móviles con la app cerrada
        try {
            $user = $notificacion->user;
            if ($user && $user->fcm_token) {
                \App\Services\FcmService::send(
                    $user->fcm_token,
                    $notificacion->titulo,
                    $notificacion->mensaje,
                    [
                        'tipo' => $notificacion->tipo,
                        'relacionado_id' => $notificacion->relacionado_id,
                    ]
                );
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("NotificacionObserver: Error enviando FCM: " . $e->getMessage());
        }
    }
}
