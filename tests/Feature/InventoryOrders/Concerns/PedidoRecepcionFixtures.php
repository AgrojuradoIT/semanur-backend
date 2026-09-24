<?php

namespace Tests\Feature\InventoryOrders\Concerns;

use App\Models\Bodega;
use App\Models\Empleado;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoRecepcion;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Fixtures U6: pedido publicado con líneas a medida y sesión de recepción
 * abierta por un receptor con empleado activo.
 */
trait PedidoRecepcionFixtures
{
    use PedidoPublicacionFixtures;

    protected function receptorOperativo(string $role = 'auxiliar_bodega'): User
    {
        $usuario = $this->usuario($role);
        $this->empleado(['user_id' => $usuario->id]);

        return $usuario;
    }

    /**
     * Crea y publica un pedido con las líneas indicadas y abre la recepción.
     *
     * Cada línea acepta: sku, descripcion, cantidad y overrides (campos
     * extra para PedidoInventarioLinea, p. ej. producto_id o ficha propuesta).
     *
     * @param  list<array<string, mixed>>  $lineas
     * @return array{0: PedidoInventario, 1: User, 2: array<string, mixed>}
     */
    protected function pedidoEnRecepcion(
        array $lineas,
        ?User $creador = null,
        ?User $receptor = null,
        ?Bodega $bodega = null,
    ): array {
        $creador ??= $this->usuario('contabilidad');
        $receptor ??= $this->receptorOperativo();

        $pedido = PedidoInventario::create([
            'bodega_id' => ($bodega ?? $this->bodega())->bodega_id,
            'created_by' => $creador->id,
            'proveedor_nombre_raw' => 'Proveedor OCR',
            'proveedor_nombre_confirmado' => 'PROVEEDOR CONFIRMADO',
            'proveedor_clave' => 'PROV-'.Str::upper(Str::random(6)),
            'referencia_factura' => 'FV-'.Str::upper(Str::random(6)),
        ]);

        foreach ($lineas as $indice => $spec) {
            $this->lineaConfirmada(
                $pedido,
                $indice + 1,
                $spec['sku'] ?? 'SKU-'.($indice + 1),
                $spec['descripcion'] ?? 'PRODUCTO '.($indice + 1),
                $spec['cantidad'] ?? '4.00',
                $spec['overrides'] ?? [],
            );
        }

        $this->documentosCompletos($pedido);

        $this->publicarPedido($pedido->fresh(), $creador)->assertOk();

        Sanctum::actingAs($receptor);

        $respuesta = $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/receipts")
            ->assertStatus(201);

        return [$pedido->fresh(['lineas']), $receptor, $respuesta->json('data')];
    }

    protected function lineaDe(PedidoInventario $pedido, int $orden): PedidoInventarioLinea
    {
        return PedidoInventarioLinea::query()
            ->where('pedido_id', $pedido->pedido_id)
            ->where('orden', $orden)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function contar(
        User $actor,
        PedidoInventario $pedido,
        int $recepcionId,
        PedidoInventarioLinea $linea,
        array $payload,
    ): TestResponse {
        Sanctum::actingAs($actor);

        return $this->putJson(
            "/api/web/inventory-orders/{$pedido->uuid}/receipts/{$recepcionId}/lines/{$linea->linea_id}",
            array_merge([
                'observacion_version' => 1,
                'conteo_total' => null,
                'conteo_aptos' => null,
                'estado_condicion' => null,
                'motivo' => null,
            ], $payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function confirmar(
        User $actor,
        PedidoInventario $pedido,
        int $recepcionId,
        array $payload = [],
    ): TestResponse {
        Sanctum::actingAs($actor);

        $version = (int) PedidoRecepcion::query()->whereKey($recepcionId)->value('version');

        return $this->postJson(
            "/api/web/inventory-orders/{$pedido->uuid}/receipts/{$recepcionId}/confirm",
            array_merge([
                'client_operation_id' => (string) Str::uuid(),
                'version' => $version,
            ], $payload),
        );
    }

    protected function abrirRecepcion(User $actor, PedidoInventario $pedido): TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/web/inventory-orders/{$pedido->uuid}/receipts");
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function productoConSku(string $sku, string $stock = '0.00', array $overrides = []): Producto
    {
        return Producto::create(array_merge([
            'categoria_id' => $this->categoriaId(),
            'producto_sku' => $sku,
            'producto_nombre' => 'Producto '.$sku,
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => $stock,
            'producto_alerta_stock_minimo' => 5,
            'producto_precio_costo' => 0,
        ], $overrides));
    }

    protected function categoriaId(): int
    {
        return (int) \App\Models\Categoria::query()->create([
            'categoria_nombre' => 'Categoría U6 '.Str::random(6),
            'categoria_tipo' => 'repuesto',
        ])->categoria_id;
    }
}
