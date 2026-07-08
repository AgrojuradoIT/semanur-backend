<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orden_trabajos', function (Blueprint $table) {
            // Cambiar columnas de DATE a DATETIME para guardar hora y fecha
            $table->dateTime('fecha_inicio')->change();
            $table->dateTime('fecha_fin')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orden_trabajos', function (Blueprint $table) {
            // Revertir a DATE (se perderá la información de hora)
            $table->date('fecha_inicio')->change();
            $table->date('fecha_fin')->nullable()->change();
        });
    }
};
