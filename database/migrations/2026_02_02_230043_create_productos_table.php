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
        Schema::create('productos', function (Blueprint $table) {
            $table->id('producto_id');
            $table->foreignId('categoria_id')->constrained('categorias', 'categoria_id')->cascadeOnDelete();
            $table->string('producto_sku')->unique();
            $table->string('producto_nombre');
            $table->string('producto_unidad_medida')->default('unidad'); // unidad, litro, galon, metro, kilo
            $table->decimal('producto_stock_actual', 10, 2)->default(0);
            $table->decimal('producto_alerta_stock_minimo', 10, 2)->default(5);
            $table->decimal('producto_precio_costo', 10, 2)->default(0);
            $table->string('producto_ubicacion')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
