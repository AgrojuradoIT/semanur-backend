<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_versions')) {
            return;
        }

        Schema::table('app_versions', function (Blueprint $table): void {
            if (! Schema::hasColumn('app_versions', 'apk_sha256')) {
                $table->char('apk_sha256', 64)->nullable()->after('apk_path');
            }
            if (! Schema::hasColumn('app_versions', 'apk_size_bytes')) {
                $table->unsignedBigInteger('apk_size_bytes')->nullable()->after('apk_sha256');
            }
            if (! Schema::hasColumn('app_versions', 'package_name')) {
                $table->string('package_name')->nullable()->after('apk_size_bytes');
            }
            if (! Schema::hasColumn('app_versions', 'signer_sha256')) {
                $table->char('signer_sha256', 64)->nullable()->after('package_name');
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: OTA integrity metadata must be retained. Restore a verified backup for rollback.'
        );
    }
};
