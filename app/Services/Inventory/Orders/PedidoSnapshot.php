<?php

namespace App\Services\Inventory\Orders;

use App\DTO\InventoryOrders\DecimalQuantity;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;

/**
 * Snapshot de auditoría de un pedido para `pedido_eventos` (antes/después).
 *
 * Incluye solo campos relevantes y NUNCA secretos: sin storage_key, rutas,
 * contenido OCR crudo, tokens ni datos personales.
 */
final class PedidoSnapshot
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function capturar(PedidoInventario $pedido): array
    {
        $lineas = $pedido->relationLoaded('lineas')
            ? $pedido->lineas
            : $pedido->lineas()->get();

        return [
            'numero' => $pedido->numero,
            'proveedor_nombre_raw' => $pedido->proveedor_nombre_raw,
            'proveedor_nombre_confirmado' => $pedido->proveedor_nombre_confirmado,
            'proveedor_clave' => $pedido->proveedor_clave,
            'referencia_factura' => $pedido->referencia_factura,
            'clave_documental_normalizada' => $pedido->clave_documental_normalizada,
            'bodega_id' => $pedido->bodega_id,
            'estado' => (string) $pedido->estado,
            'version' => $pedido->versionActual(),
            'published_at' => $pedido->published_at?->toISOString(),
            'submitted_by' => $pedido->submitted_by,
            'lineas' => $lineas
                ->sortBy('orden')
                ->values()
                ->map(fn (PedidoInventarioLinea $linea): array => [
                    'linea_id' => $linea->linea_id,
                    'orden' => $linea->orden,
                    'sku_raw' => $linea->sku_raw,
                    'sku_confirmado' => $linea->sku_confirmado,
                    'descripcion_raw' => $linea->descripcion_raw,
                    'descripcion_confirmada' => $linea->descripcion_confirmada,
                    'cantidad_esperada' => self::cantidadCanonica($linea->cantidad_esperada),
                    'unidad' => $linea->unidad,
                    'producto_id' => $linea->producto_id,
                    'estado' => (string) $linea->estado,
                    'esperado_congelado_at' => $linea->esperado_congelado_at?->toISOString(),
                ])
                ->all(),
        ];
    }

    /**
     * SQLite devuelve decimales como float; la auditoría se guarda canónica.
     */
    public static function cantidadCanonica(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            $value = number_format($value, DecimalQuantity::LEDGER_SCALE, '.', '');
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        return DecimalQuantity::tryNormalize($value, allowZero: true) ?? (string) $value;
    }
}
