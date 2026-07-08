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
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('tipo'); // stock_bajo, vencimiento_soat, mantenimiento, etc.
            $table->string('titulo');
            $table->text('mensaje');
            $table->string('relacionado_id')->nullable(); // ID del vehiculo, producto u OT
            $table->timestamp('fecha_leido')->nullable();
            $table->timestamp('fecha_atendido')->nullable();
            $table->string('prioridad')->default('media'); // baja, media, alta
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
