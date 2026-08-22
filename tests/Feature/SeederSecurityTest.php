<?php

namespace Tests\Feature;

use Tests\TestCase;

class SeederSecurityTest extends TestCase
{
    public function test_demo_seeders_have_no_literal_default_password_and_fail_closed_by_environment(): void
    {
        $files = [
            database_path('seeders/DatabaseSeeder.php'),
            database_path('seeders/AdminSeeder.php'),
            database_path('seeders/UserSeeder.php'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            $this->assertIsString($contents);
            $this->assertStringContainsString('ensureSeedingIsAllowed', $contents);
            $this->assertStringNotContainsString("Hash::make('password')", $contents);
            $this->assertStringNotContainsString('\\Hash::make(\'password\')', $contents);
        }
    }
}
