<?php

namespace Tests\Unit\InventoryOrders;

use App\Services\Inventory\Orders\Ocr\FakePedidoOcrProvider;
use App\Services\Inventory\Orders\Ocr\GoogleVisionPedidoOcrProvider;
use App\Services\Inventory\Orders\Ocr\OcrException;
use App\Services\Inventory\Orders\Ocr\OcrProveedorNoHabilitadoException;
use App\Services\Inventory\Orders\Ocr\PedidoOcrProviderFactory;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Seam de proveedores OCR: fake determinista y stub de Google que no toca red.
 */
class PedidoOcrProviderSeamTest extends TestCase
{
    public function test_fake_provider_lee_el_fixture_o01_de_forma_determinista(): void
    {
        $provider = new FakePedidoOcrProvider;

        $primera = $provider->extraer('C:\\ruta\\inexistente.jpg', 'image/jpeg');
        $segunda = $provider->extraer('otra-ruta.png', 'image/png');

        $this->assertSame('fake', $provider->nombre());
        $this->assertSame(FakePedidoOcrProvider::PROVIDER_VERSION, $provider->version());
        $this->assertSame($primera, $segunda, 'El fixture debe ser determinista e independiente de la ruta.');
        $this->assertCount(1, $primera['pages']);
        $this->assertSame('TO000057', $primera['pages'][0]['rows'][4]['cells'][0]['text']);
        $this->assertStringContainsString('TORNILLO', (string) $primera['full_text']);
    }

    public function test_fake_provider_rechaza_nombres_de_fixture_con_traversal(): void
    {
        try {
            (new FakePedidoOcrProvider)->extraer('x.jpg', 'image/jpeg', ['fixture' => '../composer.json']);
            $this->fail('Se esperaba rechazo del nombre de fixture.');
        } catch (OcrException $exception) {
            $this->assertSame('fixture_no_permitida', $exception->errorCode());
            $this->assertFalse($exception->esReintentable());
        }
    }

    public function test_fake_provider_reporta_fixture_ausente(): void
    {
        try {
            (new FakePedidoOcrProvider)->extraer('x.jpg', 'image/jpeg', ['fixture' => 'no_existe.json']);
            $this->fail('Se esperaba error de fixture ausente.');
        } catch (OcrException $exception) {
            $this->assertSame('fixture_ausente', $exception->errorCode());
        }
    }

    public function test_google_stub_falla_limpio_sin_red_ni_sdk(): void
    {
        Http::preventStrayRequests();

        $provider = PedidoOcrProviderFactory::make('google');

        $this->assertInstanceOf(GoogleVisionPedidoOcrProvider::class, $provider);

        try {
            $provider->extraer('x.jpg', 'image/jpeg', ['documento_id' => 1]);
            $this->fail('El stub de Google debe lanzar excepción.');
        } catch (OcrProveedorNoHabilitadoException $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertSame('proveedor_no_habilitado', $exception->errorCode());
            $this->assertFalse($exception->esReintentable());
            $this->assertStringContainsString('proveedor_no_habilitado', $exception->getMessage());
        }
    }

    public function test_factory_usa_fake_por_defecto_y_rechaza_desconocidos(): void
    {
        config(['inventory_orders.ocr.provider' => 'fake']);

        $this->assertInstanceOf(FakePedidoOcrProvider::class, PedidoOcrProviderFactory::make());
        $this->assertInstanceOf(GoogleVisionPedidoOcrProvider::class, PedidoOcrProviderFactory::make('GOOGLE'));

        try {
            PedidoOcrProviderFactory::make('proveedor-inventado');
            $this->fail('Un proveedor desconocido debe rechazarse.');
        } catch (OcrProveedorNoHabilitadoException $exception) {
            $this->assertSame('proveedor_no_habilitado', $exception->errorCode());
        }
    }
}
