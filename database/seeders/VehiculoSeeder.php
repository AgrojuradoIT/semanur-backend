<?php

namespace Database\Seeders;

use App\Models\Vehiculo;
use Illuminate\Database\Seeder;

class VehiculoSeeder extends Seeder
{
    public function run(): void
    {
        $vehiculos = [
            [
                'placa' => 'TR-001',
                'tipo' => 'Tractor Agricola',
                'marca' => 'John Deere',
                'modelo' => '5075E',
                'kilometraje_actual' => 0,
                'horometro_actual' => 1500,
            ],
            [
                'placa' => 'AV-002',
                'tipo' => 'Tractor Aereo', // Clave para validar el checklist especial
                'marca' => 'Air Tractor',
                'modelo' => 'AT-802',
                'kilometraje_actual' => 0,
                'horometro_actual' => 500,
            ],
            [
                'placa' => 'VQ-003',
                'tipo' => 'Volqueta',
                'marca' => 'Kenworth',
                'modelo' => 'T800',
                'kilometraje_actual' => 150000,
                'horometro_actual' => 0,
            ],
        ];

        foreach ($vehiculos as $v) {
            if (!Vehiculo::where('placa', $v['placa'])->exists()) {
                Vehiculo::create($v);
            }
        }
    }
}
