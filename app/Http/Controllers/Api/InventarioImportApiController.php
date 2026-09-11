<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InventarioImportApiController extends Controller
{
    public function importCompras(Request $request): JsonResponse
    {
        // U9 — corte definitivo del CSV de compras (plan sección 13): este
        // endpoint ya no acepta archivos ni muta stock bajo ninguna
        // circunstancia. El retorno es incondicional (NO depende del flag
        // INVENTORY_ORDERS_ENABLED): un rollback apaga los flujos nuevos pero
        // NUNCA reactiva silenciosamente esta vía de ingreso. Se conservan el
        // controlador, ComprasImportService y los datos históricos de
        // inventario_importaciones; solo se retira la mutación.
        Log::warning('Importación CSV de compras rechazada: endpoint retirado (410).', [
            'user_id' => $request->user()?->id,
            'path' => $request->path(),
        ]);

        return response()->json([
            'message' => 'La importación CSV de compras fue retirada. Registre la compra como pedido con sus evidencias y confirme la recepción física en bodega.',
            'replacement' => '/api/web/inventory-orders',
            'guidance' => 'Contabilidad crea el pedido con pantallazo Siigo + factura; bodega cuenta y confirma la recepción. Solo la recepción confirmada genera ingresos al ledger.',
        ], 410);
    }
}
