<?php

namespace App\Domain\InventoryOrders;

/**
 * Ciclo de vida de una novedad (plan secciones 6 y 8).
 */
final class NovedadEstado
{
    public const ABIERTA = 'abierta';

    public const RESUELTA = 'resuelta';

    public const CERRADA = 'cerrada';

    /** @return list<string> */
    public static function todos(): array
    {
        return [self::ABIERTA, self::RESUELTA, self::CERRADA];
    }

    /** @return list<string> */
    public static function transicionesPermitidas(string $estado): array
    {
        return match ($estado) {
            self::ABIERTA => [self::RESUELTA, self::CERRADA],
            self::RESUELTA => [self::CERRADA, self::ABIERTA],
            default => [],
        };
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
        return $estado === self::CERRADA;
    }
}
