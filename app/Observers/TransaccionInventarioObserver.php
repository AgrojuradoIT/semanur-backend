<?php

namespace App\Observers;

use App\Models\BodegaProducto;
use App\Models\TransaccionInventario;

class TransaccionInventarioObserver
{
    public function created(TransaccionInventario $transaccion): void
    {
        $producto = $transaccion->producto;

        if (!$producto) {
            return;
        }

        $tipo = strtolower((string) $transaccion->transaccion_tipo);
        $cantidad = $transaccion->transaccion_cantidad;

        if (in_array($tipo, ['entrada', 'ingreso'], true)) {
            $producto->producto_stock_actual += $cantidad;
        } elseif ($tipo === 'salida') {
            $producto->producto_stock_actual -= $cantidad;
        }

        $producto->save();

        if ($transaccion->bodega_id) {
            $this->syncBodegaProducto($transaccion, $tipo, $cantidad);
        }
    }

    private function syncBodegaProducto(TransaccionInventario $transaccion, string $tipo, float $cantidad): void
    {
        $bodegaId = $transaccion->bodega_id;
        $productoId = $transaccion->producto_id;

        $bodegaProducto = BodegaProducto::where('bodega_id', $bodegaId)
            ->where('producto_id', $productoId)
            ->first();

        $currentCantidad = $bodegaProducto ? (float) $bodegaProducto->cantidad : 0;

        if (in_array($tipo, ['entrada', 'ingreso'], true)) {
            $currentCantidad += $cantidad;
        } elseif ($tipo === 'salida') {
            $currentCantidad = max(0, $currentCantidad - $cantidad);
        }

        BodegaProducto::updateOrCreate(
            ['bodega_id' => $bodegaId, 'producto_id' => $productoId],
            ['cantidad' => $currentCantidad]
        );
    }
}
