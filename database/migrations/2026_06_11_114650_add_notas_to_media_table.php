<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('media', 'notas')) {
            Schema::table('media', function (Blueprint $table) {
                $table->text('notas')->nullable()->after('path');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: media audit notes must be retained. Restore a verified backup for rollback.',
        );
    }
};
