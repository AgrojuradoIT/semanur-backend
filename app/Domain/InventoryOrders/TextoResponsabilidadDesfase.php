<?php

namespace App\Domain\InventoryOrders;

/**
 * Versioned server-rendered responsibility text for discrepancy signing (D1/D3).
 *
 * D3 (confirmed 2026-09-10): quantities only. The text never creates employee
 * debt, payroll discounts or accounting entries; it is internal operational
 * evidence. The version travels with the signature so an old acceptance cannot
 * silently apply to a new wording.
 */
final class TextoResponsabilidadDesfase
{
    public const VERSION = 'v1';

    private function __construct() {}

    /**
     * Stored value: `<version>|<text>`. The `pedido_autorizaciones` row keeps it
     * next to `motivo` as the immutable evidence of what was accepted.
     */
    public static function conVersion(): string
    {
        return self::VERSION.'|'.self::texto();
    }

    public static function texto(): string
    {
        return 'Declaro que revisé físicamente la mercancía contada, que la cantidad apta declarada '
            .'es la que realmente existe y está disponible, y que la diferencia firmada queda '
            .'registrada como evidencia operativa interna de la recepción. No se genera deuda del '
            .'empleado, descuento de nómina ni asiento contable automático. Esta autorización cubre '
            .'únicamente cantidades, sin valoración monetaria.';
    }
}
