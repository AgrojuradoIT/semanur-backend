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
        Schema::create('novedades', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->unsignedBigInteger('empleado_id');
            $table->foreign('empleado_id')->references('id')->on('empleados')->cascadeOnDelete();
            
            $table->unsignedBigInteger('vehiculo_id')->nullable();
            $table->foreign('vehiculo_id')->references('vehiculo_id')->on('vehiculos')->nullOnDelete();
            
            $table->text('descripcion');
            $table->string('prioridad')->default('Normal'); // Urgente, Normal
            $table->boolean('pausar_actividad')->default(false);
            
            $table->unsignedBigInteger('orden_trabajo_id')->nullable();
            $table->foreign('orden_trabajo_id')->references('orden_trabajo_id')->on('orden_trabajos')->nullOnDelete();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('novedades');
    }
};
