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
        Schema::create('bodegas', function (Blueprint $table) {
            $table->id('bodega_id');
            $table->string('nombre');
            $table->string('descripcion')->nullable();
            $table->string('tipo')->default('estandar'); // estandar, recuperacion
            $table->timestamps();
        });

        // Tabla intermedia para manejar stock en múltiples bodegas
        Schema::create('bodega_producto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bodega_id')->constrained('bodegas', 'bodega_id')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos', 'producto_id')->cascadeOnDelete();
            $table->decimal('cantidad', 10, 2)->default(0);
            $table->timestamps();
            
            // Un producto solo puede estar una vez en una bodega específica
            $table->unique(['bodega_id', 'producto_id']);
        });

        // Opcional: Migrar stock actual de producto a una bodega principal por defecto
        // Esto requeriría DB::seed o similar, pero por ahora solo creamos la estructura.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bodega_producto');
        Schema::dropIfExists('bodegas');
    }
};
