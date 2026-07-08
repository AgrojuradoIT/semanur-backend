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
        Schema::create('registros_combustible', function (Blueprint $table) {
            $table->id('registro_id');
            $table->foreignId('vehiculo_id')->constrained('vehiculos', 'vehiculo_id')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('users', 'id')->cascadeOnDelete();
            $table->dateTime('fecha');
            $table->decimal('cantidad_galones', 10, 2);
            $table->decimal('valor_total', 12, 2);
            $table->decimal('horometro_actual', 10, 2)->nullable();
            $table->decimal('kilometraje_actual', 12, 2)->nullable();
            $table->string('estacion_servicio')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registros_combustible');
    }
};
