<?php

namespace Tests\Unit\InventoryOrders;

use App\DTO\InventoryOrders\DecimalQuantity;
use App\DTO\InventoryOrders\InvalidDecimalQuantityException;
use PHPUnit\Framework\TestCase;

class PedidoDecimalQuantityTest extends TestCase
{
    public function test_normalizes_valid_quantities_to_canonical_strings(): void
    {
        $cases = [
            '4.00' => '4.00',
            '4' => '4.00',
            '4,00' => '4.00',
            '0.5' => '0.50',
            '0004.5' => '4.50',
            '0.01' => '0.01',
            '99999999.99' => '99999999.99',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, DecimalQuantity::normalize((string) $input), "Fallo normalizando {$input}");
        }

        $this->assertSame('4.00', DecimalQuantity::normalize(4));
    }

    public function test_rejects_every_non_exact_quantity(): void
    {
        $invalidStrings = [
            'NaN',
            'nan',
            'Infinity',
            'inf',
            '-1.00',
            '-0.01',
            '1e3',
            '1E3',
            '4.001',
            '1,234',
            'abc',
            '',
            '   ',
            '1.234,56',
            '123456789.00',
            '4.',
            '.5',
            '+4.00',
            '4 00',
        ];

        foreach ($invalidStrings as $input) {
            $this->assertFalse(DecimalQuantity::isValid($input), "Debió rechazar la cadena '{$input}'");
        }

        $this->assertFalse(DecimalQuantity::isValid(null));
        $this->assertFalse(DecimalQuantity::isValid(4.0));
        $this->assertFalse(DecimalQuantity::isValid(NAN));
        $this->assertFalse(DecimalQuantity::isValid(INF));
        $this->assertFalse(DecimalQuantity::isValid(-4));
    }

    public function test_normalize_throws_on_invalid_input(): void
    {
        $this->expectException(InvalidDecimalQuantityException::class);

        DecimalQuantity::normalize('4.001', field: 'cantidad_esperada');
    }

    public function test_zero_is_rejected_for_expected_quantities_and_allowed_for_counts(): void
    {
        $this->expectException(InvalidDecimalQuantityException::class);
        DecimalQuantity::normalize('0.00');
    }

    public function test_explicit_zero_is_allowed_when_requested(): void
    {
        $this->assertSame('0.00', DecimalQuantity::normalize('0', allowZero: true));
        $this->assertSame('0.00', DecimalQuantity::normalize('0.0', allowZero: true));
    }

    public function test_compares_canonical_quantities_without_floats(): void
    {
        $this->assertSame(0, DecimalQuantity::compare('4.00', '4.00'));
        $this->assertSame(1, DecimalQuantity::compare('5.00', '4.99'));
        $this->assertSame(-1, DecimalQuantity::compare('9.99', '10.00'));
        $this->assertSame(0, DecimalQuantity::compare('00000004.00', '4.00'));
    }

    public function test_compare_rejects_non_canonical_input(): void
    {
        $this->expectException(InvalidDecimalQuantityException::class);

        DecimalQuantity::compare('4.001', '4.00');
    }

    public function test_ledger_scale_matches_the_real_migration(): void
    {
        $this->assertSame(2, DecimalQuantity::LEDGER_SCALE);
        $this->assertSame(10, DecimalQuantity::LEDGER_PRECISION);
        $this->assertTrue(DecimalQuantity::isCanonical('4.00'));
        $this->assertFalse(DecimalQuantity::isCanonical('4'));
        $this->assertFalse(DecimalQuantity::isCanonical('4.001'));
    }
}
