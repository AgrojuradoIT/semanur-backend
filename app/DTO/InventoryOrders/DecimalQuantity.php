<?php

namespace App\DTO\InventoryOrders;

/**
 * Normaliza cantidades decimales exactas como string canónico "123.45".
 *
 * La escala debe coincidir con el ledger real:
 * transaccion_inventarios.transaccion_cantidad es decimal(10,2) y
 * bodega_producto.cantidad también. Nunca se aceptan floats.
 */
final class DecimalQuantity
{
    public const LEDGER_PRECISION = 10;

    public const LEDGER_SCALE = 2;

    public const MAX_INTEGER_DIGITS = 8;

    private const CANONICAL_PATTERN = '/^[0-9]{1,8}\.[0-9]{2}$/';

    private function __construct() {}

    public static function normalize(string|int|float|null $value, bool $allowZero = false, string $field = 'cantidad'): string
    {
        $canonical = self::tryNormalize($value, $allowZero);

        if ($canonical === null) {
            throw new InvalidDecimalQuantityException(self::errorMessage($field));
        }

        return $canonical;
    }

    public static function tryNormalize(string|int|float|null $value, bool $allowZero = false): ?string
    {
        if ($value === null || is_float($value)) {
            return null;
        }

        if (is_int($value)) {
            if ($value < 0) {
                return null;
            }

            $raw = (string) $value;
        } else {
            $raw = trim($value);

            if ($raw === '' || strpbrk($raw, 'eE') !== false) {
                return null;
            }
        }

        if (preg_match('/^(?<integer>[0-9]+)(?:[.,](?<fraction>[0-9]+))?$/', $raw, $matches) !== 1) {
            return null;
        }

        $fraction = $matches['fraction'] ?? '';

        if (strlen($fraction) > self::LEDGER_SCALE) {
            return null;
        }

        $integer = ltrim($matches['integer'], '0');

        if ($integer === '') {
            $integer = '0';
        }

        if (strlen($integer) > self::MAX_INTEGER_DIGITS) {
            return null;
        }

        $canonical = $integer.'.'.str_pad($fraction, self::LEDGER_SCALE, '0');

        if (! $allowZero && $canonical === '0.'.str_repeat('0', self::LEDGER_SCALE)) {
            return null;
        }

        return $canonical;
    }

    public static function isValid(string|int|float|null $value, bool $allowZero = false): bool
    {
        return self::tryNormalize($value, $allowZero) !== null;
    }

    public static function isCanonical(string $value): bool
    {
        return preg_match(self::CANONICAL_PATTERN, $value) === 1;
    }

    /**
     * Compara dos cantidades canónicas sin floats ni bcmath.
     */
    public static function compare(string $a, string $b): int
    {
        $scaledA = self::scaledDigits($a);
        $scaledB = self::scaledDigits($b);

        if ($scaledA === null || $scaledB === null) {
            throw new InvalidDecimalQuantityException('Solo se comparan cantidades canónicas.');
        }

        return strlen($scaledA) <=> strlen($scaledB) ?: strcmp($scaledA, $scaledB);
    }

    private static function scaledDigits(string $value): ?string
    {
        if (! self::isCanonical($value)) {
            return null;
        }

        $digits = ltrim(str_replace('.', '', $value), '0');

        return $digits === '' ? '0' : $digits;
    }

    private static function errorMessage(string $field): string
    {
        return sprintf(
            'El campo %s debe ser una cantidad decimal exacta como string, sin signo, con máximo %d dígitos enteros y %d decimales.',
            $field,
            self::MAX_INTEGER_DIGITS,
            self::LEDGER_SCALE,
        );
    }
}
