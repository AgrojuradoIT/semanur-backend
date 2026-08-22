<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\RejectsUnsafeSeeding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    use RejectsUnsafeSeeding;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->ensureSeedingIsAllowed();
        $credentials = $this->resolveDemoPassword();

        $user = User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin Semanur',
                'password' => Hash::make($credentials['password']),
                'role' => 'admin',
            ]
        );

        if ($user->wasRecentlyCreated && $credentials['generated']) {
            $this->command?->warn("Generated local admin password: {$credentials['password']}");
        }
    }
}
