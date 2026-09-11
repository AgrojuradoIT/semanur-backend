<?php

namespace App\Services\Inventory\Orders\Ocr;

use JsonException;

/**
 * Proveedor OCR determinista para tests y entornos sin Google autorizado.
 *
 * Ignora la imagen y devuelve un fixture JSON versionado bajo
 * `tests/fixtures/ocr`. El fixture puede elegirse con
 * `$contexto['fixture']`, pero SOLO se aceptan nombres simples `*.json` del
 * directorio configurado (sin rutas ni traversal).
 */
final class FakePedidoOcrProvider implements PedidoOcrProvider
{
    public const PROVIDER_NAME = 'fake';

    public const PROVIDER_VERSION = 'fake-1.0.0';

    public const FIXTURE_POR_DEFECTO = 'siigo_pantallazo_o01.json';

    public function __construct(private readonly ?string $fixturesPath = null) {}

    public function extraer(string $rutaAbsoluta, string $mimeType, array $contexto = []): array
    {
        $fixture = (string) ($contexto['fixture'] ?? self::FIXTURE_POR_DEFECTO);
        $archivo = $this->rutaFixture($fixture);

        $contenido = file_get_contents($archivo);

        if ($contenido === false || $contenido === '') {
            throw OcrException::terminal('fixture_ilegible', sprintf('No se pudo leer el fixture OCR "%s".', $fixture));
        }

        try {
            $datos = json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw OcrException::terminal('fixture_invalido', sprintf('El fixture OCR "%s" no es JSON válido.', $fixture), $exception);
        }

        if (! is_array($datos) || ! isset($datos['pages']) || ! is_array($datos['pages'])) {
            throw OcrException::terminal('fixture_invalido', sprintf('El fixture OCR "%s" no tiene la forma cruda esperada.', $fixture));
        }

        return $datos;
    }

    public function nombre(): string
    {
        return self::PROVIDER_NAME;
    }

    public function version(): ?string
    {
        return self::PROVIDER_VERSION;
    }

    private function rutaFixture(string $fixture): string
    {
        $nombre = basename($fixture);

        if ($nombre !== $fixture || preg_match('/^[A-Za-z0-9._-]+\.json$/', $nombre) !== 1) {
            throw OcrException::terminal(
                'fixture_no_permitida',
                'Nombre de fixture no permitido: solo archivos .json del directorio de fixtures, sin rutas.',
            );
        }

        $base = $this->fixturesPath
            ?? (string) config('inventory_orders.ocr.fake_fixtures_path', base_path('tests/fixtures/ocr'));

        $ruta = rtrim($base, '/\\').DIRECTORY_SEPARATOR.$nombre;

        if (! is_file($ruta) || ! is_readable($ruta)) {
            throw OcrException::terminal('fixture_ausente', sprintf('No existe el fixture OCR "%s".', $nombre));
        }

        return $ruta;
    }
}
