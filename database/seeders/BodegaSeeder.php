<?php

namespace Database\Seeders;

use App\Models\Bodega;
use Illuminate\Database\Seeder;

class BodegaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Bodega::firstOrCreate(
            ['tipo' => 'estandar'],
            [
                'nombre' => 'Bodega Principal',
                'descripcion' => 'Bodega logística y de distribución central'
            ]
        );

        Bodega::firstOrCreate(
            ['tipo' => 'recuperacion'],
            [
                'nombre' => 'Bodega de Material Recuperado',
                'descripcion' => 'Almacén de productos devueltos o piezas usadas'
            ]
        );
    }
}
