<?php

namespace Tests\Feature\InventoryOrders\Concerns;

use App\Models\PedidoDocumento;
use App\Models\PedidoInventario;
use App\Services\Inventory\Orders\Ocr\PedidoOcrProvider;
use App\Services\Inventory\Orders\PedidoDocumentoService;
use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fixtures compartidas de U4: documentos privados con derivado real en disco,
 * proveedores OCR dobles y estructura cruda mínima para el mapper.
 */
trait PedidoOcrFixtures
{
    protected function documentoOcr(
        PedidoInventario $pedido,
        string $rol = PedidoDocumentoService::ROL_PANTALLAZO_SIIGO,
        int $revision = 1,
        array $overrides = [],
    ): PedidoDocumento {
        $key = 'pedidos/'.$pedido->uuid.'/'.Str::uuid().'/original.jpg';

        Storage::disk('local')->put($key, 'original-ocr');
        Storage::disk('local')->put(PedidoDocumentoService::derivativePathFor($key), 'preview-ocr');

        return PedidoDocumento::create(array_merge([
            'pedido_id' => $pedido->pedido_id,
            'rol' => $rol,
            'documento_revision' => $revision,
            'storage_key' => $key,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 12,
            'width' => 100,
            'height' => 100,
            'sha256' => hash('sha256', 'original-ocr'),
        ], $overrides));
    }

    protected function limpiarDocumentosOcr(): void
    {
        Storage::fake('local');
        Storage::disk('local')->deleteDirectory('pedidos');
    }

    protected function usarProveedor(PedidoOcrProvider $proveedor): void
    {
        $this->app->instance(PedidoOcrProvider::class, $proveedor);
    }

    /**
     * Proveedor doble: el callback recibe ($ruta, $mime, $contexto) y puede
     * devolver la estructura cruda o lanzar OcrException.
     */
    protected function proveedorFake(callable $extraer): PedidoOcrProvider
    {
        return new class(Closure::fromCallable($extraer)) implements PedidoOcrProvider
        {
            public function __construct(private readonly Closure $callback) {}

            public function extraer(string $rutaAbsoluta, string $mimeType, array $contexto = []): array
            {
                return ($this->callback)($rutaAbsoluta, $mimeType, $contexto);
            }

            public function nombre(): string
            {
                return 'test';
            }

            public function version(): ?string
            {
                return 'test-1';
            }
        };
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $productos  [sku, descripción, cantidad]
     * @return array<string, mixed>
     */
    protected function crudoValido(array $productos = [['TO000057', 'TORNILLO CORTAMALEZA', '4.00']], string $proveedor = 'OSCAR DARÍO DÍAZ CASARUBIAS'): array
    {
        $filas = [];
        $numero = 1;

        if ($proveedor !== '') {
            $filas[] = $this->filaCruda($numero++, ['Señor(es):', $proveedor]);
        }

        $filas[] = $this->filaCruda($numero++, ['BUSCAR', 'DESCRIPCIÓN', 'BODEGA', 'CANT', 'VALOR UNIT.', 'VALOR TOTAL']);

        foreach ($productos as $producto) {
            $filas[] = $this->filaCruda($numero++, [$producto[0], $producto[1], 'Repuestos', $producto[2], '75,000.00', '300,000.00']);
        }

        return [
            'provider' => 'test',
            'provider_version' => 'test-1',
            'full_text' => '',
            'pages' => [[
                'page_number' => 1,
                'width' => 1000,
                'height' => 2000,
                'rows' => $filas,
            ]],
        ];
    }

    /**
     * @param  list<string>  $textos
     * @return array<string, mixed>
     */
    private function filaCruda(int $numero, array $textos): array
    {
        $cells = [];
        $columna = 1;

        foreach ($textos as $texto) {
            $cells[] = [
                'column' => $columna++,
                'text' => $texto,
                'confidence' => 0.9,
                'bounding_box' => ['x' => 1, 'y' => 1, 'width' => 10, 'height' => 10],
            ];
        }

        return ['row' => $numero, 'cells' => $cells];
    }
}
