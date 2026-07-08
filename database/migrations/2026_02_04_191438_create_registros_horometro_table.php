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
        Schema::create('registros_horometro', function (Blueprint $table) {
            $table->id('registro_horometro_id');
            $table->unsignedBigInteger('vehiculo_id');
            $table->decimal('valor_anterior', 10, 2);
            $table->decimal('valor_nuevo', 10, 2);
            $table->unsignedBigInteger('usuario_id');
            $table->string('notas')->nullable();
            $table->timestamps();

            $table->foreign('vehiculo_id')->references('vehiculo_id')->on('vehiculos')->onDelete('cascade');
            $table->foreign('usuario_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registros_horometro');
    }
};
