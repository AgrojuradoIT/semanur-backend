<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\RejectsUnsafeSeeding;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;
    use RejectsUnsafeSeeding;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->ensureSeedingIsAllowed();

        $this->call([
            UserSeeder::class,
            BodegaSeeder::class,
            CategoriasSeeder::class,
            VehiculoSeeder::class,
            ProductoSeeder::class,
            CargoSeeder::class,
        ]);
    }
}
