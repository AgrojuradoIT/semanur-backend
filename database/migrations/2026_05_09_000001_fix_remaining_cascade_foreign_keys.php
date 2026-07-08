<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fix orden_trabajos.mecanico_asignado_id -> users: NO ACTION → SET NULL
        Schema::table('orden_trabajos', function (Blueprint $table) {
            $table->dropForeign(['mecanico_asignado_id']);
            $table->foreign('mecanico_asignado_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        // Fix registros_horometro.usuario_id -> users: NO ACTION → CASCADE (columna es NOT NULL)
        Schema::table('registros_horometro', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
            $table->foreign('usuario_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });

        // Fix respuestas_lista_chequeo.lista_chequeo_id -> listas_chequeo: NO ACTION → CASCADE
        Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->dropForeign(['lista_chequeo_id']);
            $table->foreign('lista_chequeo_id')
                ->references('id')
                ->on('listas_chequeo')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orden_trabajos', function (Blueprint $table) {
            $table->dropForeign(['mecanico_asignado_id']);
            $table->foreign('mecanico_asignado_id')
                ->references('id')
                ->on('users');
        });

        Schema::table('registros_horometro', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
            $table->foreign('usuario_id')
                ->references('id')
                ->on('users');
        });

        Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->dropForeign(['lista_chequeo_id']);
            $table->foreign('lista_chequeo_id')
                ->references('id')
                ->on('listas_chequeo');
        });
    }
};
