<?php

namespace App\Events\InventoryOrders;

use App\Models\PedidoAlerta;
use App\Models\PedidoEvento;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Realtime opt-in U11 para las alertas Web de pedidos (plan sección 12).
 *
 * - Canal privado POR DESTINATARIO (`pedidos.alertas.{userId}`), autorizado en
 *   `routes/channels.php` con id + capacidad `pedidos.read` explícita. Nunca
 *   usa el canal legacy `user.{id}` ni el pipeline de Notificacion/FCM.
 * - `ShouldBroadcast` (encolado, NUNCA `ShouldBroadcastNow`): el broadcast se
 *   procesa fuera del request. La bandeja persistida (`pedido_alertas`) sigue
 *   siendo la fuente de verdad; un fallo del websocket no pierde pedidos.
 * - Payload MÍNIMO: solo identificadores y revisión. Nunca nombres, facturas,
 *   texto OCR ni tokens; el detalle se obtiene por la API con Policy.
 */
class PedidoAlertaEmitida implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PedidoAlerta $alerta,
        public PedidoEvento $evento,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('pedidos.alertas.'.$this->alerta->destinatario_user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'pedido.alerta';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'alert_id' => $this->alerta->alerta_id,
            'pedido_id' => $this->alerta->pedido_id,
            'pedido_uuid' => $this->alerta->pedido?->uuid,
            'tipo' => $this->alerta->tipo,
            'revision' => $this->evento->revision,
        ];
    }
}
