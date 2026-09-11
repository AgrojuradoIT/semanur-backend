<?php

namespace Tests\Feature\InventoryOrders\Concerns;

use App\Models\PedidoDocumento;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\User;
use App\Services\Inventory\Orders\PedidoDocumentoService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Fixtures U5: pedidos publicables (proveedor confirmado, líneas revisadas y
 * ambos documentos vigentes), receptores y helper de publicación por HTTP.
 */
trait PedidoPublicacionFixtures
{
    use PedidoFixtures, PedidoOcrFixtures;

    protected function receptorHabilitado(string $role = 'auxiliar_bodega'): User
    {
        return $this->usuario($role);
    }

    protected function lineaConfirmada(
        PedidoInventario $pedido,
        int $orden = 1,
        ?string $sku = null,
        string $descripcion = 'PRODUCTO CONFIRMADO',
        string $cantidad = '4.00',
        array $overrides = [],
    ): PedidoInventarioLinea {
        $sku ??= 'SKU-CONF-'.$orden;

        return PedidoInventarioLinea::create(array_merge([
            'pedido_id' => $pedido->pedido_id,
            'orden' => $orden,
            'sku_raw' => $sku,
            'sku_confirmado' => $sku,
            'descripcion_raw' => $descripcion,
            'descripcion_confirmada' => $descripcion,
            'cantidad_esperada' => $cantidad,
        ], $overrides));
    }

    protected function documentoCompleto(
        PedidoInventario $pedido,
        string $rol = PedidoDocumentoService::ROL_PANTALLAZO_SIIGO,
        array $overrides = [],
    ): PedidoDocumento {
        return $this->documentoOcr($pedido, $rol, 1, $overrides);
    }

    protected function documentosCompletos(PedidoInventario $pedido): void
    {
        $this->documentoCompleto($pedido, PedidoDocumentoService::ROL_PANTALLAZO_SIIGO);
        $this->documentoCompleto($pedido, PedidoDocumentoService::ROL_FACTURA);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $lineaOverrides
     */
    protected function pedidoPublicable(
        ?User $creador = null,
        array $overrides = [],
        array $lineaOverrides = [],
    ): PedidoInventario {
        $pedido = PedidoInventario::create(array_merge([
            'bodega_id' => $this->bodega()->bodega_id,
            'created_by' => ($creador ?? $this->usuario('contabilidad'))->id,
            'proveedor_nombre_raw' => 'Proveedor OCR',
            'proveedor_nombre_confirmado' => 'PROVEEDOR CONFIRMADO',
            'proveedor_clave' => 'PROV-'.Str::upper(Str::random(6)),
            'referencia_factura' => 'FV-'.Str::upper(Str::random(6)),
        ], $overrides));

        $this->lineaConfirmada($pedido, 1, null, 'PRODUCTO CONFIRMADO', '4.00', $lineaOverrides);
        $this->documentosCompletos($pedido);

        return $pedido->fresh(['lineas', 'documentos']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function publicarPedido(PedidoInventario $pedido, User $actor, array $overrides = []): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/publish", array_merge([
            'client_operation_id' => (string) Str::uuid(),
            'version' => $pedido->fresh()->versionActual(),
        ], $overrides));
    }
}
