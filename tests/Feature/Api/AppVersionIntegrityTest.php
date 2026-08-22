<?php

namespace Tests\Feature\Api;

use App\Models\AppVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppVersionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_version_exposes_verified_artifact_metadata_and_rate_limited_download(): void
    {
        Storage::fake('public');
        $contents = 'signed-apk-fixture';
        Storage::disk('public')->put('apks/semanur.apk', $contents);

        $version = AppVersion::create([
            'version' => '9.9.1',
            'min_version' => '9.9.0',
            'apk_path' => 'apks/semanur.apk',
            'release_notes' => 'Integrity test',
            'force_update' => true,
            'package_name' => 'com.semanur.hub',
            'signer_sha256' => str_repeat('A', 64),
        ]);

        $this->assertSame(hash('sha256', $contents), $version->apk_sha256);
        $this->assertSame(strlen($contents), $version->apk_size_bytes);

        $version->activate();

        $this->getJson('/api/app/version')
            ->assertOk()
            ->assertJsonPath('latest_version', '9.9.1')
            ->assertJsonPath('apk_sha256', hash('sha256', $contents))
            ->assertJsonPath('apk_size_bytes', strlen($contents))
            ->assertJsonPath('package_name', 'com.semanur.hub')
            ->assertJsonPath('signer_sha256', str_repeat('A', 64));

        $this->get('/api/app/download')
            ->assertOk()
            ->assertHeader('ETag', '"'.hash('sha256', $contents).'"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_tampered_candidate_cannot_replace_the_current_active_version(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('apks/current.apk', 'current');
        Storage::disk('public')->put('apks/candidate.apk', 'candidate');

        $current = $this->version('9.9.1', 'apks/current.apk');
        $current->activate();
        $candidate = $this->version('9.9.2', 'apks/candidate.apk');

        Storage::disk('public')->put('apks/candidate.apk', 'tampered');

        try {
            $candidate->activate();
            $this->fail('Tampered APK activation should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('apk_path', $exception->errors());
        }

        $this->assertTrue($current->refresh()->is_active);
        $this->assertFalse($candidate->refresh()->is_active);
    }

    private function version(string $version, string $path): AppVersion
    {
        return AppVersion::create([
            'version' => $version,
            'min_version' => '9.9.0',
            'apk_path' => $path,
            'package_name' => 'com.semanur.hub',
            'signer_sha256' => str_repeat('B', 64),
        ]);
    }
}
