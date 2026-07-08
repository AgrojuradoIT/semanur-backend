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
        if (!Schema::hasTable('programacion')) {
            Schema::create('programacion', function (Blueprint $table) {
                $table->id();
                $table->date('fecha');
                $table->foreignId('empleado_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('vehiculo_id')->nullable();
                $table->foreign('vehiculo_id')->references('vehiculo_id')->on('vehiculos')->nullOnDelete();
                
                $table->string('labor');
                $table->string('ubicacion')->nullable();
                $table->enum('estado', ['pendiente', 'en_progreso', 'pausado', 'completado'])->default('pendiente');
                
                $table->unsignedBigInteger('orden_trabajo_id')->nullable();
                $table->foreign('orden_trabajo_id')->references('orden_trabajo_id')->on('orden_trabajos')->nullOnDelete();

                $table->boolean('es_novedad')->default(false);
                
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('programacion');
    }
};
