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
        Schema::table('programacion', function (Blueprint $table) {
            $table->index('fecha', 'programacion_fecha_idx');
            $table->index(['fecha', 'empleado_id'], 'programacion_fecha_empleado_idx');
            $table->index(['fecha', 'vehiculo_id'], 'programacion_fecha_vehiculo_idx');
        });

        Schema::table('empleados', function (Blueprint $table) {
            $table->index('nombres', 'empleados_nombres_idx');
            $table->index('cargo', 'empleados_cargo_idx');
            $table->index('estado', 'empleados_estado_idx');
        });

        Schema::table('vehiculos', function (Blueprint $table) {
            $table->index('tipo', 'vehiculos_tipo_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->dropIndex('vehiculos_tipo_idx');
        });

        Schema::table('empleados', function (Blueprint $table) {
            $table->dropIndex('empleados_nombres_idx');
            $table->dropIndex('empleados_cargo_idx');
            $table->dropIndex('empleados_estado_idx');
        });

        Schema::table('programacion', function (Blueprint $table) {
            $table->dropIndex('programacion_fecha_idx');
            $table->dropIndex('programacion_fecha_empleado_idx');
            $table->dropIndex('programacion_fecha_vehiculo_idx');
        });
    }
};
