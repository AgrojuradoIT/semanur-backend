<?php

namespace App\Domain\InventoryOrders;

/**
 * Máquina de estados del pedido (plan sección 6).
 */
final class PedidoEstado
{
    public const BORRADOR = 'borrador';

    public const PENDIENTE_RECEPCION = 'pendiente_recepcion';

    public const EN_VALIDACION = 'en_validacion';

    public const PARCIAL_CON_NOVEDAD = 'parcial_con_novedad';

    public const RECIBIDO = 'recibido';

    public const RECIBIDO_CON_DESFASE = 'recibido_con_desfase';

    public const CANCELADO = 'cancelado';

    /** @return list<string> */
    public static function todos(): array
    {
        return [
            self::BORRADOR,
            self::PENDIENTE_RECEPCION,
            self::EN_VALIDACION,
            self::PARCIAL_CON_NOVEDAD,
            self::RECIBIDO,
            self::RECIBIDO_CON_DESFASE,
            self::CANCELADO,
        ];
    }

    /** @return list<string> */
    public static function transicionesPermitidas(string $estado): array
    {
        return match ($estado) {
            self::BORRADOR => [self::PENDIENTE_RECEPCION, self::CANCELADO],
            self::PENDIENTE_RECEPCION => [self::EN_VALIDACION, self::BORRADOR, self::CANCELADO],
            self::EN_VALIDACION => [self::RECIBIDO, self::RECIBIDO_CON_DESFASE, self::PARCIAL_CON_NOVEDAD, self::CANCELADO],
            self::PARCIAL_CON_NOVEDAD => [self::PARCIAL_CON_NOVEDAD, self::RECIBIDO, self::RECIBIDO_CON_DESFASE],
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
        return in_array($estado, [self::RECIBIDO, self::RECIBIDO_CON_DESFASE, self::CANCELADO], true);
    }

    public static function esEditable(string $estado): bool
    {
        return $estado === self::BORRADOR;
    }

    public static function esperadoCongelado(string $estado): bool
    {
        return ! self::esEditable($estado);
    }
}
