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
            $table->string('linea')->nullable()->after('modelo');
            $table->string('serial_vin')->nullable()->after('linea');
            $table->string('combustible')->nullable()->after('serial_vin');
            $table->string('cilindraje')->nullable()->after('combustible');
            $table->date('fecha_inicio_soat')->nullable()->after('fecha_vencimiento_soat');
            $table->date('fecha_inicio_tecnomecanica')->nullable()->after('fecha_vencimiento_tecnomecanica');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->dropColumn([
                'linea',
                'serial_vin',
                'combustible',
                'cilindraje',
                'fecha_inicio_soat',
                'fecha_inicio_tecnomecanica'
            ]);
        });
    }
};
