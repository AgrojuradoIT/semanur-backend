<?php

namespace App\Services;

use App\Models\Producto;
use Illuminate\Support\Facades\Log;

class CombustibleService
{
    /**
     * Resuelve el producto de inventario correspondiente al tipo de combustible.
     * Busca primero en la categoría "combustible", luego en todo el catálogo.
     */
    public function resolveCombustibleProducto(string $tipo): ?Producto
    {
        $needle = strtoupper($tipo === 'gasolina' ? 'GASOLINA' : 'ACPM');

        $byCategoria = Producto::whereHas('categoria', function ($q) {
            $q->where('categoria_tipo', 'combustible')
                ->orWhereRaw('LOWER(categoria_nombre) LIKE ?', ['%combustible%']);
        })
            ->where(function ($q) use ($needle) {
                $q->whereRaw('UPPER(producto_nombre) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('UPPER(producto_sku) LIKE ?', ["%{$needle}%"]);
            })
            ->orderByRaw('CASE WHEN UPPER(producto_nombre) = ? THEN 0 ELSE 1 END', [$needle])
            ->first();

        if ($byCategoria) {
            return $byCategoria;
        }

        $fallback = Producto::where(function ($q) use ($needle) {
            $q->whereRaw('UPPER(producto_nombre) LIKE ?', ["%{$needle}%"])
                ->orWhereRaw('UPPER(producto_sku) LIKE ?', ["%{$needle}%"]);
        })
            ->orderByRaw('CASE WHEN UPPER(producto_nombre) = ? THEN 0 ELSE 1 END', [$needle])
            ->first();

        if ($fallback) {
            Log::warning("CombustibleService: producto '{$tipo}' encontrado fuera de categoría combustible", [
                'producto_id' => $fallback->producto_id,
                'producto_nombre' => $fallback->producto_nombre,
            ]);
        }

        return $fallback;
    }

    /**
     * Determina el tipo de referencia y las notas para la transacción de inventario.
     */
    public function buildReferencia(string $tipoDestino, ?int $vehiculoId, ?string $terceroNombre, ?string $labor): array
    {
        $refType = null;
        $refId = null;
        $notas = 'Tanqueo interno';

        if (in_array($tipoDestino, ['vehiculo', 'equipo_menor'])) {
            $refType = 'Vehiculo';
            $refId = $vehiculoId;
            $etiqueta = $tipoDestino === 'equipo_menor' ? 'equipo menor' : 'vehículo';
            $notas .= " para {$etiqueta} ID {$vehiculoId}";
            if ($tipoDestino === 'equipo_menor' && $terceroNombre) {
                $notas .= " entregado a tercero: {$terceroNombre}";
            }
        } elseif ($tipoDestino === 'empleado') {
            $refType = 'EmpleadoTexto';
            $notas .= " para empleado: {$terceroNombre}";
        } else {
            $refType = 'Tercero';
            $notas .= " para tercero: {$terceroNombre}";
        }

        if ($labor) {
            $notas .= " | Labor: {$labor}";
        }

        return [$refType, $refId, $notas];
    }
}
