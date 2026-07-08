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
        // 1. Actualizar tabla 'programacion'
        Schema::table('programacion', function (Blueprint $table) {
            // Eliminar FK anterior si existe
            $table->dropForeign(['empleado_id']);
            
            // Re-vincular a tabla empleados(id)
            $table->foreign('empleado_id')
                  ->references('id')
                  ->on('empleados')
                  ->cascadeOnDelete();
        });

        // 2. Actualizar tabla 'registros_combustible'
        Schema::table('registros_combustible', function (Blueprint $table) {
            // Eliminar FK anterior si existe
            $table->dropForeign(['empleado_id']);
            
            // Re-vincular a tabla empleados(id)
            $table->foreign('empleado_id')
                  ->references('id')
                  ->on('empleados')
                  ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir a users(id) si es necesario
        Schema::table('programacion', function (Blueprint $table) {
            $table->dropForeign(['empleado_id']);
            $table->foreign('empleado_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('registros_combustible', function (Blueprint $table) {
            $table->dropForeign(['empleado_id']);
            $table->foreign('empleado_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
