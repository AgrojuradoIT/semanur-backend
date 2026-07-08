<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear usuarios solo si no existen
        if (!User::where('email', 'admin@semanur.com')->exists()) {
            User::create([
                'name' => 'Admin Semanur',
                'email' => 'admin@semanur.com',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);
        }

        if (!User::where('email', 'usuario@test.com')->exists()) {
            User::create([
                'name' => 'Usuario Test',
                'email' => 'usuario@test.com',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);
        }

        // Crear Mecánicos para pruebas
        $mecanicos = ['Juan Mecánico', 'Pedro Reparador', 'Carlos Técnico'];
        foreach ($mecanicos as $nombre) {
            $email = strtolower(str_replace(' ', '.', $nombre)) . '@semanur.com';
            if (!User::where('email', $email)->exists()) {
                User::create([
                    'name' => $nombre,
                    'email' => $email,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]);
            }
        }
    }
}
