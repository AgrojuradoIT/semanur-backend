<?php

namespace App\Services\Inventory\Orders\Ocr;

/**
 * Resolución del proveedor OCR.
 *
 * El default viene de `inventory_orders.ocr.provider` (por defecto `fake`).
 * Si el contenedor tiene un binding explícito de PedidoOcrProvider — patrón
 * usado por tests o por una integración futura — ese binding tiene prioridad.
 *
 * Nota de arquitectura: no se toca un ServiceProvider existente (AppServiceProvider
 * pertenece al trabajo previo). PedidoOcrService recibe el binding por
 * constructor cuando existe y cae a esta factory cuando no.
 */
final class PedidoOcrProviderFactory
{
    public static function make(?string $nombre = null): PedidoOcrProvider
    {
        $nombre = strtolower(trim((string) ($nombre ?? config('inventory_orders.ocr.provider', 'fake'))));

        return match ($nombre) {
            FakePedidoOcrProvider::PROVIDER_NAME => new FakePedidoOcrProvider,
            GoogleVisionPedidoOcrProvider::PROVIDER_NAME => new GoogleVisionPedidoOcrProvider,
            '' => throw new OcrProveedorNoHabilitadoException(
                'proveedor_no_habilitado: no hay proveedor OCR configurado (inventory_orders.ocr.provider).',
            ),
            default => throw new OcrProveedorNoHabilitadoException(sprintf(
                'proveedor_no_habilitado: proveedor OCR desconocido "%s".',
                $nombre,
            )),
        };
    }

    public static function resolver(?string $nombre = null): PedidoOcrProvider
    {
        if (app()->bound(PedidoOcrProvider::class)) {
            $bound = app(PedidoOcrProvider::class);

            if ($bound instanceof PedidoOcrProvider) {
                return $bound;
            }
        }

        return self::make($nombre);
    }
}
