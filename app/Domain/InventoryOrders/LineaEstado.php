<?php

namespace App\Domain\InventoryOrders;

/**
 * Máquina de estados de la línea (plan sección 6).
 */
final class LineaEstado
{
    public const PENDIENTE = 'pendiente';

    public const CONFORME = 'conforme';

    public const NOVEDAD = 'novedad';

    public const RECIBIDA = 'recibida';

    /**
     * Ingreso parcial con saldo abierto (U10): la línea original ingresó Q=A
     * y su remanente D=E−A vive en una línea hija vinculada. Terminal para
     * esta línea; solo se alcanza vía split en la confirmación.
     */
    public const RECIBIDA_PARCIAL = 'recibida_parcial';

    public const RECIBIDA_CON_DESFASE = 'recibida_con_desfase';

    public const CIERRE_SIN_INGRESO = 'cierre_sin_ingreso';

    /** @return list<string> */
    public static function todos(): array
    {
        return [
            self::PENDIENTE,
            self::CONFORME,
            self::NOVEDAD,
            self::RECIBIDA,
            self::RECIBIDA_PARCIAL,
            self::RECIBIDA_CON_DESFASE,
            self::CIERRE_SIN_INGRESO,
        ];
    }

    /** @return list<string> */
    public static function transicionesPermitidas(string $estado): array
    {
        return match ($estado) {
            self::PENDIENTE => [self::CONFORME, self::NOVEDAD],
            self::CONFORME => [self::NOVEDAD, self::RECIBIDA, self::RECIBIDA_PARCIAL, self::RECIBIDA_CON_DESFASE],
            self::NOVEDAD => [self::CONFORME, self::RECIBIDA_PARCIAL, self::RECIBIDA_CON_DESFASE, self::CIERRE_SIN_INGRESO],
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
        return in_array($estado, [self::RECIBIDA, self::RECIBIDA_PARCIAL, self::RECIBIDA_CON_DESFASE, self::CIERRE_SIN_INGRESO], true);
    }

    public static function admiteDesfaseAutorizado(string $estado): bool
    {
        return in_array($estado, [self::CONFORME, self::NOVEDAD], true);
    }
}
