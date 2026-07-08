<?php

namespace Database\Seeders;

use App\Models\Cargo;
use Illuminate\Database\Seeder;

class CargoSeeder extends Seeder
{
    public function run(): void
    {
        $cargos = [
            ['nombre' => 'Almacenista', 'orden' => 1],
            ['nombre' => 'Auxiliar de Cable Vía', 'orden' => 2],
            ['nombre' => 'Auxiliar de Mantenimiento', 'orden' => 3],
            ['nombre' => 'Auxiliar de Retroexcavadora', 'orden' => 4],
            ['nombre' => 'Auxiliar de Taller', 'orden' => 5],
            ['nombre' => 'Auxiliar SST', 'orden' => 6],
            ['nombre' => 'Conductor de Volqueta', 'orden' => 7],
            ['nombre' => 'Coordinador de Tractores Aéreos', 'orden' => 8],
            ['nombre' => 'Electricista', 'orden' => 9],
            ['nombre' => 'Mecánico', 'orden' => 10],
            ['nombre' => 'Motosierrista', 'orden' => 11],
            ['nombre' => 'Operador de Retroexcavadora', 'orden' => 12],
            ['nombre' => 'Operario de Oficios Varios', 'orden' => 13],
            ['nombre' => 'Operario de Plataforma', 'orden' => 14],
            ['nombre' => 'Soldador', 'orden' => 15],
            ['nombre' => 'Tractorista Aéreo', 'orden' => 16],
            ['nombre' => 'Tractorista Terrestre', 'orden' => 17],
        ];

        foreach ($cargos as $cargo) {
            Cargo::firstOrCreate(['nombre' => $cargo['nombre']], $cargo);
        }
    }
}
