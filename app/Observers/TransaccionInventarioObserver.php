<?php

namespace App\Observers;

use App\Models\BodegaProducto;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class TransaccionInventarioObserver
{
    /**
     * Project before the ledger insert while its enclosing transaction holds
     * deterministic locks. A failed insert rolls the projection back as well.
     */
    public function creating(TransaccionInventario $transaccion): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Las transacciones de inventario deben crearse dentro de DB::transaction().');
        }

        $tipo = strtolower((string) $transaccion->transaccion_tipo);
        $cantidad = round((float) $transaccion->transaccion_cantidad, 2);

        if (! in_array($tipo, ['entrada', 'ingreso', 'salida', 'transferencia'], true)) {
            $this->fail('transaccion_tipo', 'El tipo de movimiento de inventario no es válido.');
        }

        if ($cantidad <= 0 || $cantidad > 99999999.99) {
            $this->fail('transaccion_cantidad', 'La cantidad del movimiento está fuera del rango permitido.');
        }

        // Every projector takes locks in this order: product, warehouses, pivots.
        $producto = Producto::query()
            ->whereKey($transaccion->producto_id)
            ->lockForUpdate()
            ->first();

        if (! $producto) {
            $this->fail('producto_id', 'El producto del movimiento no existe.');
        }

        if ($tipo === 'transferencia') {
            $this->projectTransfer($transaccion, $producto, $cantidad);

            return;
        }

        $this->projectIngresoOrSalida($transaccion, $producto, $tipo, $cantidad);
    }

    private function projectIngresoOrSalida(
        TransaccionInventario $transaccion,
        Producto $producto,
        string $tipo,
        float $cantidad
    ): void {
        if ($transaccion->bodega_origen_id || $transaccion->bodega_destino_id) {
            $this->fail('bodega_id', 'Los campos de origen y destino solo aplican a transferencias.');
        }

        $bodegaId = $transaccion->bodega_id ? (int) $transaccion->bodega_id : null;
        if ($bodegaId === null && ! $this->isExactLegacyReversal($transaccion, $tipo, $cantidad)) {
            $this->fail('bodega_id', 'Todo ingreso o salida nuevo debe identificar su bodega.');
        }

        $stockGlobal = round((float) $producto->producto_stock_actual, 2);
        $isIngreso = in_array($tipo, ['entrada', 'ingreso'], true);

        if (! $isIngreso && $stockGlobal < $cantidad) {
            $this->fail('transaccion_cantidad', "Stock global insuficiente. Disponible: {$stockGlobal}.");
        }

        if ($bodegaId !== null) {
            $this->lockWarehouses([$bodegaId]);
            $bodegaProducto = BodegaProducto::query()
                ->where('bodega_id', $bodegaId)
                ->where('producto_id', $producto->producto_id)
                ->lockForUpdate()
                ->first();

            $stockBodega = round((float) ($bodegaProducto?->cantidad ?? 0), 2);
            if (! $isIngreso && (! $bodegaProducto || $stockBodega < $cantidad)) {
                $this->fail('transaccion_cantidad', "Stock insuficiente en la bodega seleccionada. Disponible: {$stockBodega}.");
            }

            $this->persistWarehouseBalance(
                $bodegaId,
                (int) $producto->producto_id,
                $isIngreso ? $stockBodega + $cantidad : $stockBodega - $cantidad,
                $bodegaProducto !== null
            );
        }

        $nuevoStockGlobal = $isIngreso ? $stockGlobal + $cantidad : $stockGlobal - $cantidad;
        $producto->forceFill(['producto_stock_actual' => round($nuevoStockGlobal, 2)])->save();
    }

    private function projectTransfer(
        TransaccionInventario $transaccion,
        Producto $producto,
        float $cantidad
    ): void {
        if ($transaccion->bodega_id) {
            $this->fail('bodega_id', 'Una transferencia debe usar bodega_origen_id y bodega_destino_id.');
        }

        $origenId = (int) $transaccion->bodega_origen_id;
        $destinoId = (int) $transaccion->bodega_destino_id;
        if ($origenId < 1 || $destinoId < 1 || $origenId === $destinoId) {
            $this->fail('bodega_destino_id', 'La transferencia requiere bodegas distintas de origen y destino.');
        }

        $this->lockWarehouses([$origenId, $destinoId]);
        $balances = BodegaProducto::query()
            ->where('producto_id', $producto->producto_id)
            ->whereIn('bodega_id', [$origenId, $destinoId])
            ->orderBy('bodega_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('bodega_id');

        $origen = $balances->get($origenId);
        $destino = $balances->get($destinoId);
        $stockOrigen = round((float) ($origen?->cantidad ?? 0), 2);
        $stockDestino = round((float) ($destino?->cantidad ?? 0), 2);

        if (! $origen || $stockOrigen < $cantidad) {
            $this->fail('transaccion_cantidad', "Stock insuficiente en la bodega de origen. Disponible: {$stockOrigen}.");
        }

        $this->persistWarehouseBalance($origenId, (int) $producto->producto_id, $stockOrigen - $cantidad, true);
        $this->persistWarehouseBalance($destinoId, (int) $producto->producto_id, $stockDestino + $cantidad, $destino !== null);
    }

    private function lockWarehouses(array $warehouseIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $warehouseIds)));
        sort($ids, SORT_NUMERIC);

        $locked = DB::table('bodegas')
            ->whereIn('bodega_id', $ids)
            ->orderBy('bodega_id')
            ->lockForUpdate()
            ->pluck('bodega_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($locked !== $ids) {
            $this->fail('bodega_id', 'Una de las bodegas del movimiento no existe.');
        }
    }

    private function persistWarehouseBalance(int $bodegaId, int $productoId, float $cantidad, bool $exists): void
    {
        $cantidad = round($cantidad, 2);
        if ($cantidad < 0) {
            throw new LogicException('La proyección de inventario no puede producir saldos negativos.');
        }

        if ($exists) {
            DB::table('bodega_producto')
                ->where('bodega_id', $bodegaId)
                ->where('producto_id', $productoId)
                ->update(['cantidad' => $cantidad, 'updated_at' => now()]);

            return;
        }

        DB::table('bodega_producto')->insert([
            'bodega_id' => $bodegaId,
            'producto_id' => $productoId,
            'cantidad' => $cantidad,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Historical global-only events may only be counter-posted exactly. New
     * unscoped movements remain forbidden.
     */
    private function isExactLegacyReversal(TransaccionInventario $transaccion, string $tipo, float $cantidad): bool
    {
        if (! $transaccion->reverses_transaction_id) {
            return false;
        }

        $original = TransaccionInventario::query()
            ->whereKey($transaccion->reverses_transaction_id)
            ->lockForUpdate()
            ->first();

        if (! $original || $original->bodega_id !== null) {
            return false;
        }

        $originalType = strtolower((string) $original->transaccion_tipo);
        $oppositeType = match (true) {
            $originalType === 'salida' => in_array($tipo, ['entrada', 'ingreso'], true),
            in_array($originalType, ['entrada', 'ingreso'], true) => $tipo === 'salida',
            default => false,
        };

        return $oppositeType
            && (int) $original->producto_id === (int) $transaccion->producto_id
            && round((float) $original->transaccion_cantidad, 2) === $cantidad;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
