<?php

namespace App\Domain\InventoryOrders;

/**
 * Discrete responses over an open pedido novedad (U7; plan sections 5, 11, 12).
 *
 * A response is documentation only: it never moves stock, quantities,
 * authorizations or costs. `respuesta_contable` and
 * `entrega_complementaria_informada` alert the enabled warehouse (a new count is
 * required, there is no automatic ingress); `nota_bodega` is event-only.
 */
final class RespuestaTipo
{
    public const RESPUESTA_CONTABLE = 'respuesta_contable';

    public const ENTREGA_COMPLEMENTARIA_INFORMADA = 'entrega_complementaria_informada';

    public const NOTA_BODEGA = 'nota_bodega';

    /** @return list<string> */
    public static function todos(): array
    {
        return [
            self::RESPUESTA_CONTABLE,
            self::ENTREGA_COMPLEMENTARIA_INFORMADA,
            self::NOTA_BODEGA,
        ];
    }

    public static function existe(string $tipo): bool
    {
        return in_array($tipo, self::todos(), true);
    }

    public static function generaAlerta(string $tipo): bool
    {
        return in_array($tipo, [self::RESPUESTA_CONTABLE, self::ENTREGA_COMPLEMENTARIA_INFORMADA], true);
    }
}
