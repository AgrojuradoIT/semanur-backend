<?php

namespace App\Console\Commands;

use App\Services\Inventory\Orders\PedidoEventDispatcher;
use Illuminate\Console\Command;

/**
 * Scanner idempotente de la outbox de pedidos (U4).
 *
 * Recupera los eventos `ocr_solicitado` que quedaron pendientes (por ejemplo,
 * caída entre commit y despacho) y encola el job correspondiente. El reclamo es
 * atómico: repetir el comando nunca duplica trabajo. El scheduling pertenece a
 * U9; este comando no se registra en el scheduler todavía.
 */
class DispatchPedidoEventos extends Command
{
    protected $signature = 'inventory-orders:dispatch-events
                            {--limit=100 : Máximo de eventos pendientes a despachar en esta corrida}';

    protected $description = 'Outbox: despacha eventos pendientes de pedidos (OCR) de forma idempotente';

    public function handle(PedidoEventDispatcher $dispatcher): int
    {
        $limite = (int) $this->option('limit');

        if ($limite < 1) {
            $this->error('--limit debe ser un entero mayor o igual a 1.');

            return self::FAILURE;
        }

        $limite = min($limite, 1000);

        $despachados = $dispatcher->despacharPendientes($limite);

        $this->info(sprintf('%d evento(s) pendiente(s) despachado(s).', $despachados));

        return self::SUCCESS;
    }
}
