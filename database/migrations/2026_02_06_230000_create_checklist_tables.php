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
        Schema::create('listas_chequeo', function (Blueprint $table) {
            $table->id('id');
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->string('tipo_vehiculo')->nullable(); // Para filtrar por tipo (camioneta, tractor, etc.)
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('items_lista_chequeo', function (Blueprint $table) {
            $table->id('id');
            $table->foreignId('lista_chequeo_id')->constrained('listas_chequeo')->cascadeOnDelete();
            $table->string('pregunta');
            $table->enum('tipo_respuesta', ['cumple_falla', 'texto', 'numero'])->default('cumple_falla');
            $table->integer('orden')->default(0);
            $table->boolean('es_critico')->default(false); // Si falla, rechaza el preoperacional
            $table->timestamps();
        });

        Schema::create('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->id('id');
            $table->foreignId('lista_chequeo_id')->nullable()->constrained('listas_chequeo')->nullOnDelete();
            $table->foreignId('vehiculo_id')->constrained('vehiculos', 'vehiculo_id');
            $table->unsignedBigInteger('operador_id'); // Empleado ID, sin foreign key estricto
            $table->dateTime('fecha');
            $table->json('respuestas'); // { "item_1_id": "cumple", "item_2_id": "texto..." }
            $table->enum('estado', ['aprobado', 'rechazado'])->default('aprobado');
            $table->text('observaciones_generales')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('respuestas_lista_chequeo');
        Schema::dropIfExists('items_lista_chequeo');
        Schema::dropIfExists('listas_chequeo');
    }
};
