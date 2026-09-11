<?php

namespace App\Domain\InventoryOrders;

/**
 * Condición física declarada por quien cuenta (U6, plan secciones 5 y 8).
 *
 * La condición es una observación humana del estado de la mercancía, no una
 * autorización: una línea con C = A = E pero condición distinta de `conforme`
 * sigue siendo novedad (R09) y no ingresa stock.
 */
final class CondicionLinea
{
    public const CONFORME = 'conforme';

    public const DANADO = 'danado';

    public const SKU_INCORRECTO = 'sku_incorrecto';

    public const OTRO = 'otro';

    /** @return list<string> */
    public static function todos(): array
    {
        return [
            self::CONFORME,
            self::DANADO,
            self::SKU_INCORRECTO,
            self::OTRO,
        ];
    }

    public static function existe(string $condicion): bool
    {
        return in_array($condicion, self::todos(), true);
    }

    public static function esConforme(?string $condicion): bool
    {
        return (string) $condicion === self::CONFORME;
    }
}
