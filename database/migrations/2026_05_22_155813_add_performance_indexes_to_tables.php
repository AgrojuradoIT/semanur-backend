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
        Schema::table('notificaciones', function (Blueprint $table) {
            $table->index(['user_id', 'tipo', 'relacionado_id', 'fecha_leido'], 'notif_dedup_idx');
        });

        Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->index(['vehiculo_id', 'fecha'], 'respuestas_vehiculo_fecha_idx');
            $table->index(['operador_id', 'fecha'], 'respuestas_operador_fecha_idx');
        });

        Schema::table('novedades', function (Blueprint $table) {
            $table->index('fecha', 'novedades_fecha_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('novedades', function (Blueprint $table) {
            $table->dropIndex('novedades_fecha_idx');
        });

        Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->dropIndex('respuestas_vehiculo_fecha_idx');
            $table->dropIndex('respuestas_operador_fecha_idx');
        });

        Schema::table('notificaciones', function (Blueprint $table) {
            $table->dropIndex('notif_dedup_idx');
        });
    }
};
