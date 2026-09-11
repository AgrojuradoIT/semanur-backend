<?php

namespace App\Services\Inventory\Orders;

use App\Jobs\ProcesarPedidoOcr;
use App\Models\PedidoDocumento;
use App\Models\PedidoEvento;
use App\Models\PedidoOcrIntento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Despachador de la outbox de pedidos (plan sección 8).
 *
 * Los eventos nacen `pendiente` dentro de la misma transacción que el cambio
 * que los origina. El controlador despacha después del commit; si el proceso
 * cae entre commit y despacho, el scanner idempotente
 * (`inventory-orders:dispatch-events`) recupera los eventos pendientes.
 *
 * Idempotencia: el reclamo es un UPDATE atómico condicionado a que el evento
 * siga pendiente; un evento ya despachado nunca se reencola. Si el despacho
 * revienta después de encolar, un reintento reencola el job, que a su vez es
 * idempotente (intento terminal => no-op).
 */
class PedidoEventDispatcher
{
    public const EVENTO_OCR_SOLICITADO = PedidoOcrService::EVENTO_OCR_SOLICITADO;

    /** @var list<string> */
    public const EVENTOS_PROPIOS = [self::EVENTO_OCR_SOLICITADO];

    /**
     * Despacha hasta $limite eventos pendientes de los tipos propios.
     * Devuelve cuántos se despacharon.
     */
    public function despacharPendientes(int $limite = 100): int
    {
        $eventos = PedidoEvento::query()
            ->whereIn('event_type', self::EVENTOS_PROPIOS)
            ->where('estado_despacho', 'pendiente')
            ->orderBy('evento_id')
            ->limit(max(1, $limite))
            ->get();

        $despachados = 0;

        foreach ($eventos as $evento) {
            if ($this->despachar($evento)) {
                $despachados++;
            }
        }

        return $despachados;
    }

    /**
     * Despacha el evento `ocr_solicitado` pendiente asociado a un intento.
     * Devuelve false si no hay evento pendiente (ya despachado o inexistente).
     */
    public function despacharPorIntento(PedidoOcrIntento $intento): bool
    {
        $pedidoId = PedidoDocumento::query()
            ->whereKey($intento->documento_id)
            ->value('pedido_id');

        $evento = PedidoEvento::query()
            ->where('event_type', self::EVENTO_OCR_SOLICITADO)
            ->where('estado_despacho', 'pendiente')
            ->when($pedidoId !== null, fn ($query) => $query->where('pedido_id', $pedidoId))
            ->orderByDesc('evento_id')
            ->get()
            ->first(fn (PedidoEvento $candidato): bool => (int) ($candidato->datos_despues['intento_id'] ?? 0) === (int) $intento->intento_id);

        return $evento !== null && $this->despachar($evento);
    }

    public function despachar(PedidoEvento $evento): bool
    {
        if (! in_array((string) $evento->event_type, self::EVENTOS_PROPIOS, true)) {
            return false;
        }

        $reclamado = PedidoEvento::query()
            ->whereKey($evento->evento_id)
            ->where('estado_despacho', 'pendiente')
            ->update([
                'intentos_despacho' => DB::raw('intentos_despacho + 1'),
                'updated_at' => now(),
            ]);

        if ($reclamado === 0) {
            return false;
        }

        $intentoId = (int) ($evento->datos_despues['intento_id'] ?? 0);

        if ($intentoId <= 0) {
            PedidoEvento::query()
                ->whereKey($evento->evento_id)
                ->update([
                    'estado_despacho' => 'fallido',
                    'despachado_at' => now(),
                    'updated_at' => now(),
                ]);

            Log::warning('pedido_evento.dispatch_sin_intento', ['evento_id' => $evento->evento_id]);

            return false;
        }

        ProcesarPedidoOcr::dispatch($intentoId);

        PedidoEvento::query()
            ->whereKey($evento->evento_id)
            ->update([
                'estado_despacho' => 'despachado',
                'despachado_at' => now(),
                'updated_at' => now(),
            ]);

        return true;
    }
}
