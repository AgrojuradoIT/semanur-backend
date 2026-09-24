<?php

namespace App\Domain\InventoryOrders;

/**
 * Tipos de novedad detectados en servidor al confirmar una recepción (U6).
 *
 * El tipo se calcula con las cantidades y la condición registradas; nunca se
 * acepta desde el cliente como autorización. La resolución, respuestas y
 * autorización de desfase pertenecen a U7.
 */
final class NovedadTipo
{
    public const FALTANTE = 'faltante';

    public const SOBRANTE = 'sobrante';

    public const DANADO = 'danado';

    public const SKU_INCORRECTO = 'sku_incorrecto';

    public const APTOS_INSUFICIENTES = 'aptos_insuficientes';

    public const FICHA_INCOMPLETA = 'ficha_incompleta';

    public const OTRO = 'otro';

    /** @return list<string> */
    public static function todos(): array
    {
        return [
            self::FALTANTE,
            self::SOBRANTE,
            self::DANADO,
            self::SKU_INCORRECTO,
            self::APTOS_INSUFICIENTES,
            self::FICHA_INCOMPLETA,
            self::OTRO,
        ];
    }
}
