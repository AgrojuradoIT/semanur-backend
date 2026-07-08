<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategoriasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categorias = [
            // Herramientas
            ['categoria_nombre' => 'Herramientas', 'categoria_tipo' => 'herramienta'],

            // Combustibles
            ['categoria_nombre' => 'Combustibles (Gasolina/ACPM)', 'categoria_tipo' => 'combustible'],

            // Insumos
            ['categoria_nombre' => 'Aceites y Lubricantes', 'categoria_tipo' => 'insumo'],
            ['categoria_nombre' => 'Aceite usado', 'categoria_tipo' => 'insumo'],
            ['categoria_nombre' => 'Chatarra', 'categoria_tipo' => 'insumo'],
            ['categoria_nombre' => 'Soldadura', 'categoria_tipo' => 'insumo'],
            ['categoria_nombre' => 'Tornilleria', 'categoria_tipo' => 'insumo'],
            ['categoria_nombre' => 'Tubos y Laminas', 'categoria_tipo' => 'insumo'],

            // Servicios
            ['categoria_nombre' => 'Servicios', 'categoria_tipo' => 'servicio'],
            ['categoria_nombre' => 'Servicio Volquetas', 'categoria_tipo' => 'servicio'],
            ['categoria_nombre' => 'Servicios Retro', 'categoria_tipo' => 'servicio'],
            ['categoria_nombre' => 'Servicios Tractores', 'categoria_tipo' => 'servicio'],

            // Repuestos (General)
            ['categoria_nombre' => 'Accesorios', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Baterias', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Baterias Usadas', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Cable Via', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Correas', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Eléctricos', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Empaques', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Filtración', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Latoneria y Pintura', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Llantas', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Mangueras y Acoples', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Motor y Caja', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Productos', 'categoria_tipo' => 'repuesto'], // Generico
            ['categoria_nombre' => 'Retenedores', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Rodamientos', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Suspension', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Tractores Aereos', 'categoria_tipo' => 'repuesto'],
            ['categoria_nombre' => 'Transmision', 'categoria_tipo' => 'repuesto'],
        ];

        foreach ($categorias as $cat) {
            DB::table('categorias')->updateOrInsert(
                ['categoria_nombre' => $cat['categoria_nombre']],
                ['categoria_tipo' => $cat['categoria_tipo']]
            );
        }
    }
}
