<?php

namespace Database\Seeders;

use App\Models\Producto;
use App\Models\Categoria;
use Illuminate\Database\Seeder;

class ProductoSeeder extends Seeder
{
    public function run(): void
    {
        $catCombustible = Categoria::where('categoria_tipo', 'combustible')->first();
        $catRepuesto = Categoria::where('categoria_tipo', 'repuesto')->first();
        $catHerramienta = Categoria::where('categoria_tipo', 'herramienta')->first();

        // Combustibles
        if ($catCombustible) {
            Producto::firstOrCreate(
                ['producto_sku' => 'AL000001'],
                [
                    'producto_nombre' => 'Gasolina Corriente',
                    'categoria_id' => $catCombustible->categoria_id,
                    'producto_stock_actual' => 1000,
                    'producto_precio_costo' => 12000,
                    // 'precio_venta' => 0, // No está en fillable
                    'producto_ubicacion' => 'Tanque Principal',
                ]
            );
            Producto::firstOrCreate(
                ['producto_sku' => 'AL000002'],
                [
                    'producto_nombre' => 'ACPM / Diesel',
                    'categoria_id' => $catCombustible->categoria_id,
                    'producto_stock_actual' => 2000,
                    'producto_precio_costo' => 10000,
                    'producto_ubicacion' => 'Tanque Secundario',
                ]
            );
        }

        // Repuestos
        if ($catRepuesto) {
            Producto::firstOrCreate(
                ['producto_sku' => 'REP-001'],
                [
                    'producto_nombre' => 'Filtro de Aceite JD',
                    'categoria_id' => $catRepuesto->categoria_id,
                    'producto_stock_actual' => 10,
                    'producto_precio_costo' => 50000,
                    'producto_ubicacion' => 'Bodega A',
                ]
            );
        }

        // Herramientas
        if ($catHerramienta) {
            Producto::firstOrCreate(
                ['producto_sku' => 'TOOL-001'],
                [
                    'producto_nombre' => 'Taladro Percutor',
                    'categoria_id' => $catHerramienta->categoria_id,
                    'producto_stock_actual' => 2,
                    'producto_precio_costo' => 300000,
                    'producto_ubicacion' => 'Estante Herramientas',
                ]
            );
            Producto::firstOrCreate(
                ['producto_sku' => 'TOOL-002'],
                [
                    'producto_nombre' => 'Juego de Llaves Mixtas',
                    'categoria_id' => $catHerramienta->categoria_id,
                    'producto_stock_actual' => 5,
                    'producto_precio_costo' => 150000,
                    'producto_ubicacion' => 'Estante Herramientas',
                ]
            );
        }
    }
}
