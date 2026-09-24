<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Str;
use RuntimeException;

trait RejectsUnsafeSeeding
{
    protected function ensureSeedingIsAllowed(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'Database seeders are disabled outside local/testing environments.'
            );
        }
    }

    /** @return array{password: string, generated: bool} */
    protected function resolveDemoPassword(): array
    {
        $configured = env('SEED_USER_PASSWORD');

        if ($configured === null || trim((string) $configured) === '') {
            return [
                'password' => Str::password(24, symbols: true),
                'generated' => true,
            ];
        }

        $password = (string) $configured;
        if (strlen($password) < 12
            || preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/\d/', $password) !== 1
            || preg_match('/[^A-Za-z0-9]/', $password) !== 1) {
            throw new RuntimeException(
                'SEED_USER_PASSWORD must contain at least 12 characters, upper/lower case, a number, and a symbol.'
            );
        }

        return ['password' => $password, 'generated' => false];
    }
}
