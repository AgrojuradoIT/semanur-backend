<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\RejectsUnsafeSeeding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    use RejectsUnsafeSeeding;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->ensureSeedingIsAllowed();
        $credentials = $this->resolveDemoPassword();
        $createdAny = false;

        // Crear usuarios solo si no existen
        if (!User::where('email', 'admin@semanur.com')->exists()) {
            User::create([
                'name' => 'Admin Semanur',
                'email' => 'admin@semanur.com',
                'password' => Hash::make($credentials['password']),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]);
            $createdAny = true;
        }

        if (!User::where('email', 'usuario@test.com')->exists()) {
            User::create([
                'name' => 'Usuario Test',
                'email' => 'usuario@test.com',
                'password' => Hash::make($credentials['password']),
                'role' => 'operador',
                'email_verified_at' => now(),
            ]);
            $createdAny = true;
        }

        // Crear Mecánicos para pruebas
        $mecanicos = ['Juan Mecánico', 'Pedro Reparador', 'Carlos Técnico'];
        foreach ($mecanicos as $nombre) {
            $email = strtolower(str_replace(' ', '.', $nombre)) . '@semanur.com';
            if (!User::where('email', $email)->exists()) {
                User::create([
                    'name' => $nombre,
                    'email' => $email,
                    'password' => Hash::make($credentials['password']),
                    'role' => 'mecanico',
                    'email_verified_at' => now(),
                ]);
                $createdAny = true;
            }
        }

        if ($createdAny && $credentials['generated']) {
            $this->command?->warn("Generated local demo-user password: {$credentials['password']}");
        }
    }
}
