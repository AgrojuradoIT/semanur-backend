<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fix orden_trabajos -> vehiculos: change NO ACTION to CASCADE
        Schema::table('orden_trabajos', function (Blueprint $table) {
            $table->dropForeign(['vehiculo_id']);
            $table->foreign('vehiculo_id')
                ->references('vehiculo_id')
                ->on('vehiculos')
                ->cascadeOnDelete();
        });

        // Fix respuestas_lista_chequeo -> vehiculos: change NO ACTION to CASCADE
        Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->dropForeign(['vehiculo_id']);
            $table->foreign('vehiculo_id')
                ->references('vehiculo_id')
                ->on('vehiculos')
                ->cascadeOnDelete();
        });

        // Fix sesiones_trabajo -> orden_trabajos: change NO ACTION to CASCADE
        Schema::table('sesiones_trabajo', function (Blueprint $table) {
            $table->dropForeign(['orden_trabajo_id']);
            $table->foreign('orden_trabajo_id')
                ->references('orden_trabajo_id')
                ->on('orden_trabajos')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orden_trabajos', function (Blueprint $table) {
            $table->dropForeign(['vehiculo_id']);
            $table->foreign('vehiculo_id')
                ->references('vehiculo_id')
                ->on('vehiculos');
        });

        Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->dropForeign(['vehiculo_id']);
            $table->foreign('vehiculo_id')
                ->references('vehiculo_id')
                ->on('vehiculos');
        });

        Schema::table('sesiones_trabajo', function (Blueprint $table) {
            $table->dropForeign(['orden_trabajo_id']);
            $table->foreign('orden_trabajo_id')
                ->references('orden_trabajo_id')
                ->on('orden_trabajos');
        });
    }
};
