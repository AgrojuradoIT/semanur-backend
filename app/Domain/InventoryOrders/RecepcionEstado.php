<?php

namespace App\Domain\InventoryOrders;

/**
 * Máquina de estados de la recepción (plan sección 6).
 */
final class RecepcionEstado
{
    public const BORRADOR = 'borrador';

    public const CONFIRMADA = 'confirmada';

    /**
     * Sesión de borrador invalidada por un retiro documental (plan sección 6):
     * deja de admitir conteos y no puede reanudarse. Se conserva como
     * historial (R15); la re-publicación abre una sesión nueva.
     */
    public const INVALIDADA = 'invalidada';

    /** @return list<string> */
    public static function todos(): array
    {
        return [self::BORRADOR, self::CONFIRMADA, self::INVALIDADA];
    }

    /** @return list<string> */
    public static function transicionesPermitidas(string $estado): array
    {
        return $estado === self::BORRADOR ? [self::CONFIRMADA, self::INVALIDADA] : [];
    }

    public static function existe(string $estado): bool
    {
        return in_array($estado, self::todos(), true);
    }

    public static function permiteTransicion(string $estado, string $nuevoEstado): bool
    {
        return self::existe($nuevoEstado)
            && in_array($nuevoEstado, self::transicionesPermitidas($estado), true);
    }

    public static function esTerminal(string $estado): bool
    {
        return in_array($estado, [self::CONFIRMADA, self::INVALIDADA], true);
    }

    public static function admiteConteos(string $estado): bool
    {
        return $estado === self::BORRADOR;
    }
}
