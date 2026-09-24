<?php

namespace Tests\Feature\InventoryOrders\Concerns;

use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoNovedad;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Fixtures U7: pedidos con novedad abierta, firma de desfase, respuestas e
 * historial por HTTP.
 */
trait PedidoDesfaseFixtures
{
    use PedidoRecepcionFixtures;

    protected function configurarD1(
        bool $habilitado = true,
        array $roles = ['auxiliar_bodega'],
        bool $cierreSinIngreso = false,
    ): void {
        config([
            'inventory_orders.discrepancy_authorization_enabled' => $habilitado,
            'inventory_orders.discrepancy_authorizer_roles' => $roles,
            'inventory_orders.cierre_sin_ingreso_permitido' => $cierreSinIngreso,
        ]);
    }

    /**
     * Pedido publicado con dos líneas: la primera ingresa conforme en la
     * primera confirmación y la segunda queda con novedad abierta.
     *
     * La novedad queda con conteo C=3/A=2 sobre E=4 (tipo `faltante`): al ser
     * C > A no es un faltante puro y no hay split (U10), así la línea conserva
     * su novedad abierta firmable. Los conteos con A=0 o sobrante se piden con
     * `aptos` explícito igual al `contado`.
     *
     * @return array{0: PedidoInventario, 1: User, 2: PedidoInventarioLinea, 3: PedidoNovedad, 4: int}
     */
    protected function pedidoConNovedadAbierta(
        string $esperado = '4.00',
        string $contado = '3.00',
        ?User $creador = null,
        ?User $receptor = null,
        string $aptos = '2.00',
    ): array {
        $sufijo = Str::upper(Str::random(6));
        $creador ??= $this->usuario('contabilidad');
        $receptor ??= $this->receptorOperativo();

        $productoOk = $this->productoConSku("SKU-U7-OK-{$sufijo}");
        $productoNovedad = $this->productoConSku("SKU-U7-NOV-{$sufijo}");

        [$pedido, , $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => "SKU-U7-OK-{$sufijo}", 'overrides' => ['producto_id' => $productoOk->producto_id]],
            [
                'sku' => "SKU-U7-NOV-{$sufijo}",
                'cantidad' => $esperado,
                'overrides' => ['producto_id' => $productoNovedad->producto_id],
            ],
        ], $creador, $receptor);

        $lineaOk = $this->lineaDe($pedido, 1);
        $lineaNovedad = $this->lineaDe($pedido, 2);

        $this->contar($receptor, $pedido, (int) $recepcion['recepcion_id'], $lineaOk, [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'conforme',
        ])->assertOk();

        $this->contar($receptor, $pedido, (int) $recepcion['recepcion_id'], $lineaNovedad, [
            'conteo_total' => $contado,
            'conteo_aptos' => $aptos,
            'estado_condicion' => 'conforme',
        ])->assertOk();

        $this->confirmar($receptor, $pedido, (int) $recepcion['recepcion_id'])->assertOk();

        $novedad = PedidoNovedad::query()
            ->where('pedido_linea_id', $lineaNovedad->linea_id)
            ->where('estado', 'abierta')
            ->sole();

        return [$pedido->fresh(), $receptor, $lineaNovedad, $novedad, (int) $recepcion['recepcion_id']];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function autorizar(
        User $actor,
        PedidoInventario $pedido,
        int $issueId,
        array $payload = [],
    ): TestResponse {
        Sanctum::actingAs($actor);

        return $this->postJson(
            "/api/web/inventory-orders/{$pedido->uuid}/discrepancies/{$issueId}/authorize",
            array_merge([
                'motivo' => 'Faltante verificado físicamente en doble conteo',
                'aceptacion_responsabilidad' => true,
            ], $payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function responder(
        User $actor,
        PedidoInventario $pedido,
        int $issueId,
        array $payload = [],
    ): TestResponse {
        Sanctum::actingAs($actor);

        return $this->postJson(
            "/api/web/inventory-orders/{$pedido->uuid}/discrepancies/{$issueId}/responses",
            array_merge([
                'tipo' => 'respuesta_contable',
                'mensaje' => 'Se solicitó al proveedor la entrega complementaria.',
            ], $payload),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function historial(User $actor, PedidoInventario $pedido, array $query = []): TestResponse
    {
        Sanctum::actingAs($actor);

        $url = "/api/web/inventory-orders/{$pedido->uuid}/history";

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $this->getJson($url);
    }
}
