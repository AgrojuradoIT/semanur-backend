<?php

namespace App\Services\Inventory\Orders\Ocr;

use App\Models\PedidoOcrIntento;

/**
 * Lease atómico adquirido por un worker sobre un intento OCR.
 *
 * El finalizar/fallar solo aplica si `lease_token` sigue siendo el mismo: un
 * worker vencido o reemplazado no puede sobrescribir el resultado de un intento
 * más nuevo (fencing).
 */
final class OcrLease
{
    public function __construct(
        public readonly PedidoOcrIntento $intento,
        public readonly string $token,
    ) {}
}
