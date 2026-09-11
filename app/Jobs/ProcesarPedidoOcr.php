<?php

namespace App\Jobs;

use App\Models\PedidoOcrIntento;
use App\Services\Inventory\Orders\PedidoOcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Procesa un intento OCR en cola (U4).
 *
 * - Respeta la conexión/cola configuradas (nunca asume sync).
 * - timeout y tries salen de config; timeout 60 s por defecto.
 * - Backoff exponencial con jitter para errores reintentables (429/5xx/timeout).
 * - Idempotente: un intento terminal/vigente produce no-op.
 */
class ProcesarPedidoOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(public readonly int $intentoId)
    {
        $conexion = config('inventory_orders.ocr.queue_connection');

        if (is_string($conexion) && trim($conexion) !== '') {
            $this->onConnection(trim($conexion));
        }

        $cola = config('inventory_orders.ocr.queue');

        if (is_string($cola) && trim($cola) !== '') {
            $this->onQueue(trim($cola));
        }

        $this->tries = max(1, (int) config('inventory_orders.ocr.max_intentos', 3));
        $this->timeout = max(1, (int) config('inventory_orders.ocr.timeout', 60));
    }

    public function handle(PedidoOcrService $service): void
    {
        $intento = PedidoOcrIntento::query()->find($this->intentoId);

        if ($intento === null) {
            return;
        }

        $resultado = $service->procesar($intento);

        if ($resultado->esReintentable()) {
            throw new RuntimeException($resultado->mensaje ?? 'OCR reintentable; se aplicará backoff.');
        }
    }

    /**
     * Backoff exponencial acotado con jitter para fallos transitorios.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        $base = max(1, (int) config('inventory_orders.ocr.backoff_base_seconds', 10));
        $maximo = max($base, (int) config('inventory_orders.ocr.backoff_max_seconds', 300));
        $jitter = max(1, intdiv($base, 2));
        $esperas = [];

        for ($intento = 0; $intento < max(1, $this->tries - 1); $intento++) {
            $esperas[] = min($maximo, $base * (2 ** $intento)) + random_int(0, $jitter);
        }

        return $esperas;
    }

    public function failed(?Throwable $exception): void
    {
        $intento = PedidoOcrIntento::query()->find($this->intentoId);

        if ($intento === null) {
            return;
        }

        app(PedidoOcrService::class)->marcarFallidoPorAgotamiento($intento, $exception?->getMessage());
    }
}
