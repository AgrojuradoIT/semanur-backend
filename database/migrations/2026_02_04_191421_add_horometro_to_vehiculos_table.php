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
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->decimal('horometro_actual', 10, 2)->default(0)->after('modelo');
            $table->decimal('horometro_proximo_mantenimiento', 10, 2)->nullable()->after('horometro_actual');
            $table->decimal('kilometraje_actual', 10, 2)->default(0)->after('horometro_proximo_mantenimiento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->dropColumn(['horometro_actual', 'horometro_proximo_mantenimiento', 'kilometraje_actual']);
        });
    }
};
