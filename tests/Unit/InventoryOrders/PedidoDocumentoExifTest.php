<?php

namespace Tests\Unit\InventoryOrders;

use App\Services\Inventory\Orders\JpegExifOrientation;
use PHPUnit\Framework\TestCase;
use Tests\Feature\InventoryOrders\Concerns\PedidoDocumentoFixtures;

class PedidoDocumentoExifTest extends TestCase
{
    use PedidoDocumentoFixtures;

    public function test_non_jpeg_or_empty_input_falls_back_to_default(): void
    {
        $this->assertSame(1, JpegExifOrientation::read(''));
        $this->assertSame(1, JpegExifOrientation::read('no es una imagen'));
        $this->assertSame(1, JpegExifOrientation::read($this->pngBytes()));
        $this->assertSame(1, JpegExifOrientation::read("\xFF\xD8"));
    }

    public function test_jpeg_without_exif_returns_default(): void
    {
        $this->assertSame(1, JpegExifOrientation::read($this->jpegBytes()));
    }

    public function test_little_endian_orientation_is_read(): void
    {
        foreach ([2, 3, 4, 5, 6, 7, 8] as $orientation) {
            $this->assertSame(
                $orientation,
                JpegExifOrientation::read($this->jpegConOrientacion($orientation)),
                "Orientación II {$orientation} no fue leída.",
            );
        }
    }

    public function test_big_endian_orientation_is_read(): void
    {
        $jpeg = $this->insertarExifOrientacion($this->jpegBytes(), 8, 'MM');

        $this->assertSame(8, JpegExifOrientation::read($jpeg));
    }

    public function test_out_of_range_or_malformed_exif_falls_back_to_default(): void
    {
        // Valor fuera del rango EXIF 1-8.
        $this->assertSame(1, JpegExifOrientation::read($this->jpegConOrientacion(9)));
        $this->assertSame(1, JpegExifOrientation::read($this->jpegConOrientacion(0)));

        // APP1 truncado: el largo declarado excede el buffer.
        $jpeg = $this->jpegConOrientacion(6);
        $this->assertSame(1, JpegExifOrientation::read(substr($jpeg, 0, 12)));

        // Orden de bytes inválido en el TIFF.
        $payload = "Exif\x00\x00".'XX'.pack('v', 42).pack('V', 8);
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
        $bogus = substr($this->jpegBytes(), 0, 2).$segment.substr($this->jpegBytes(), 2);
        $this->assertSame(1, JpegExifOrientation::read($bogus));
    }
}
