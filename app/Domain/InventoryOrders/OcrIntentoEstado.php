<?php

namespace App\Domain\InventoryOrders;

/**
 * Máquina de estados del intento OCR (plan sección 6).
 */
final class OcrIntentoEstado
{
    public const PENDIENTE = 'pendiente';

    public const PROCESANDO = 'procesando';

    public const COMPLETADO = 'completado';

    public const REQUIERE_REVISION = 'requiere_revision';

    public const FALLIDO = 'fallido';

    public const CANCELADO = 'cancelado';

    public const OBSOLETO = 'obsoleto';

    /** @return list<string> */
    public static function todos(): array
    {
        return [
            self::PENDIENTE,
            self::PROCESANDO,
            self::COMPLETADO,
            self::REQUIERE_REVISION,
            self::FALLIDO,
            self::CANCELADO,
            self::OBSOLETO,
        ];
    }

    /** @return list<string> */
    public static function transicionesPermitidas(string $estado): array
    {
        return match ($estado) {
            self::PENDIENTE => [self::PROCESANDO, self::CANCELADO],
            self::PROCESANDO => [self::COMPLETADO, self::REQUIERE_REVISION, self::FALLIDO, self::CANCELADO, self::OBSOLETO],
            self::COMPLETADO, self::REQUIERE_REVISION => [self::OBSOLETO],
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
        return in_array($estado, [self::FALLIDO, self::CANCELADO, self::OBSOLETO], true);
    }

    public static function esResultadoVigente(string $estado): bool
    {
        return in_array($estado, [self::COMPLETADO, self::REQUIERE_REVISION], true);
    }
}
