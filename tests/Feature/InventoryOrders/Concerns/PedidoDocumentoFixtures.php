<?php

namespace Tests\Feature\InventoryOrders\Concerns;

use Illuminate\Http\UploadedFile;

/**
 * Fixtures binarias reales (GD) para el pipeline de documentos U3. Evita
 * depender de archivos de imagen versionados y permite construir EXIF
 * sintético para probar orientación.
 */
trait PedidoDocumentoFixtures
{
    protected function jpegBytes(int $width = 64, int $height = 48, int $quality = 80): string
    {
        $image = imagecreatetruecolor($width, $height);

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $color = imagecolorallocate($image, ($x * 17) % 256, ($y * 31) % 256, ($x + $y) % 256);
                imagesetpixel($image, $x, $y, $color);
            }
        }

        ob_start();
        imagejpeg($image, null, $quality);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }

    protected function pngBytes(int $width = 64, int $height = 48): string
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 250, 250, 250);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $background);
        $red = imagecolorallocate($image, 200, 20, 30);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), intdiv($height, 2), $red);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }

    protected function uploadJpeg(string $name = 'evidencia.jpg', int $width = 64, int $height = 48): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->jpegBytes($width, $height));
    }

    protected function uploadPng(string $name = 'evidencia.png', int $width = 64, int $height = 48): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pngBytes($width, $height));
    }

    protected function jpegConOrientacion(int $orientation, int $width = 64, int $height = 48): string
    {
        return $this->insertarExifOrientacion($this->jpegBytes($width, $height), $orientation);
    }

    /**
     * Inserta un APP1 EXIF mínimo con el tag Orientation delante de un JPEG
     * real generado por GD.
     */
    protected function insertarExifOrientacion(string $jpeg, int $orientation, string $byteOrder = 'II'): string
    {
        $pack16 = $byteOrder === 'II'
            ? static fn (int $value): string => pack('v', $value)
            : static fn (int $value): string => pack('n', $value);
        $pack32 = $byteOrder === 'II'
            ? static fn (int $value): string => pack('V', $value)
            : static fn (int $value): string => pack('N', $value);

        $tiff = $byteOrder
            .$pack16(42)
            .$pack32(8)
            .$pack16(1)
            .$pack16(0x0112).$pack16(3).$pack32(1).$pack16($orientation)."\x00\x00"
            .$pack32(0);

        $payload = "Exif\x00\x00".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }
}
