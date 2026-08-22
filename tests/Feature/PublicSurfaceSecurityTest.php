<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicSurfaceSecurityTest extends TestCase
{
    public function test_public_directory_contains_no_operational_or_diagnostic_php_scripts(): void
    {
        $forbidden = [
            'deploy.php',
            'clear_cache.php',
            'link_storage.php',
            'info.php',
            'check.php',
            'simple.php',
            'pathinfo.php',
            'rewrite-test.php',
            'test.php',
        ];

        foreach ($forbidden as $file) {
            $this->assertFileDoesNotExist(public_path($file));
        }

        $phpFiles = glob(public_path('*.php')) ?: [];
        $this->assertSame([public_path('index.php')], $phpFiles);
    }

    public function test_public_server_baseline_denies_extra_php_and_direct_sensitive_storage(): void
    {
        $configuration = file_get_contents(public_path('.htaccess'));

        $this->assertStringContainsString('<FilesMatch "\\.php$">', $configuration);
        $this->assertStringContainsString('<Files "index.php">', $configuration);
        $this->assertStringContainsString('storage/(?:apks|media)', $configuration);
        $this->assertStringContainsString('zip|tar|tgz|gz|7z|rar|sql|sqlite|bak|backup|old|log', $configuration);
        $this->assertStringContainsString('Options -Indexes', $configuration);
    }

    public function test_operational_helpers_reject_web_execution(): void
    {
        foreach (glob(base_path('scripts/operations/*.php')) ?: [] as $script) {
            $contents = file_get_contents($script);
            $this->assertStringContainsString("PHP_SAPI !== 'cli'", $contents);
        }
    }
}
