<?php

namespace App\Services\Inventory;

use App\Models\Bodega;
use Illuminate\Validation\ValidationException;

final class InventoryWarehouseResolver
{
    public function resolve(?int $requestedId = null): Bodega
    {
        $warehouseId = $requestedId ?? config('inventory.default_bodega_id');

        if ($warehouseId === null || (int) $warehouseId <= 0) {
            throw ValidationException::withMessages([
                'bodega_id' => 'Debe seleccionar una bodega o configurar INVENTORY_DEFAULT_BODEGA_ID.',
            ]);
        }

        $warehouse = Bodega::query()->find((int) $warehouseId);

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'bodega_id' => 'La bodega seleccionada o configurada no existe.',
            ]);
        }

        return $warehouse;
    }
}
