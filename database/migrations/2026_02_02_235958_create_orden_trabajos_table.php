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
        Schema::create('orden_trabajos', function (Blueprint $table) {
            $table->id('orden_trabajo_id');
            $table->foreignId('vehiculo_id')->constrained('vehiculos', 'vehiculo_id');
            $table->foreignId('mecanico_asignado_id')->nullable()->constrained('users', 'id');
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->string('estado')->default('Abierta'); // Abierta, En Progreso, Cerrada
            $table->string('prioridad')->default('Media'); // Alta, Media, Baja
            $table->text('descripcion');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orden_trabajos');
    }
};
