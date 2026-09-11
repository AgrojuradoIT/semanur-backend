<?php

namespace Tests\Feature\InventoryOrders;

use App\Models\PedidoDocumento;
use App\Models\PedidoInventario;
use App\Services\Inventory\Orders\PedidoDocumentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\InventoryOrders\Concerns\PedidoFixtures;
use Tests\TestCase;

class PedidoDocumentoReaperTest extends TestCase
{
    use PedidoFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inventory_orders.enabled' => true,
            'inventory_orders.documents.orphan_ttl_hours' => 24,
            'inventory_orders.documents.disk' => 'local',
        ]);

        Storage::disk('local')->deleteDirectory('pedidos');
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('pedidos');

        parent::tearDown();
    }

    public function test_dry_run_lists_but_never_deletes_referenced_or_recent_objects(): void
    {
        $pedido = $this->pedido();
        [$referencia, $derivado] = $this->documentoReferenciado($pedido);

        $huerfano = $this->archivo("pedidos/{$pedido->uuid}/huerfano/original.jpg", 'huerfano');
        $this->envejecer($huerfano, 48);
        $this->envejecer($referencia, 48);
        $this->envejecer($derivado, 48);

        $this->artisan('inventory-orders:reap-document-orphans')->assertExitCode(0);

        $this->assertTrue(Storage::disk('local')->exists($huerfano), 'El dry-run no debe borrar.');
        $this->assertTrue(Storage::disk('local')->exists($referencia));
        $this->assertTrue(Storage::disk('local')->exists($derivado));
    }

    public function test_force_deletes_only_old_unreferenced_objects(): void
    {
        $pedido = $this->pedido();
        [$referencia, $derivado] = $this->documentoReferenciado($pedido);

        $huerfanoViejo = $this->archivo("pedidos/{$pedido->uuid}/huerfano/original.jpg", 'viejo');
        $huerfanoFresco = $this->archivo("pedidos/{$pedido->uuid}/fresco/original.jpg", 'fresco');
        $this->envejecer($huerfanoViejo, 48);

        // Los archivos referenciados también son viejos: deben sobrevivir.
        $this->envejecer($referencia, 72);
        $this->envejecer($derivado, 72);

        $this->artisan('inventory-orders:reap-document-orphans', ['--force' => true])->assertExitCode(0);

        $this->assertFalse(Storage::disk('local')->exists($huerfanoViejo));
        $this->assertTrue(Storage::disk('local')->exists($huerfanoFresco), 'Un objeto reciente no es huérfano elegible.');
        $this->assertTrue(Storage::disk('local')->exists($referencia), 'Nunca borrar un original referenciado.');
        $this->assertTrue(Storage::disk('local')->exists($derivado), 'Nunca borrar el preview de un original referenciado.');
    }

    public function test_limit_bounds_how_many_orphans_are_processed(): void
    {
        $pedido = $this->pedido();

        $huerfanos = [];

        foreach (['a', 'b', 'c'] as $carpeta) {
            $ruta = $this->archivo("pedidos/{$pedido->uuid}/{$carpeta}/original.jpg", $carpeta);
            $this->envejecer($ruta, 48);
            $huerfanos[] = $ruta;
        }

        $this->artisan('inventory-orders:reap-document-orphans', [
            '--force' => true,
            '--limit' => 2,
        ])->assertExitCode(0);

        $restantes = array_values(array_filter(
            $huerfanos,
            fn (string $ruta): bool => Storage::disk('local')->exists($ruta),
        ));

        $this->assertCount(1, $restantes, '--limit debe acotar el borrado a 2 de 3 huérfanos.');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function documentoReferenciado(PedidoInventario $pedido): array
    {
        $referencia = "pedidos/{$pedido->uuid}/referenciado/original.jpg";
        $derivado = PedidoDocumentoService::derivativePathFor($referencia);

        Storage::disk('local')->put($referencia, 'evidencia');
        Storage::disk('local')->put($derivado, 'preview');

        PedidoDocumento::create([
            'pedido_id' => $pedido->pedido_id,
            'rol' => PedidoDocumentoService::ROL_FACTURA,
            'documento_revision' => 1,
            'storage_key' => $referencia,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 9,
            'width' => 10,
            'height' => 10,
            'sha256' => str_repeat('a', 64),
        ]);

        return [$referencia, $derivado];
    }

    private function archivo(string $ruta, string $contenido): string
    {
        Storage::disk('local')->put($ruta, $contenido);

        return $ruta;
    }

    private function envejecer(string $ruta, int $horas): void
    {
        $absolute = Storage::disk('local')->path($ruta);
        touch($absolute, time() - ($horas * 3600));
        clearstatcache(true, $absolute);
    }
}
