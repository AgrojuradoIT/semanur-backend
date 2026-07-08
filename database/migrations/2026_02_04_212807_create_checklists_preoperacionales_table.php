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
        Schema::create('checklists_preoperacionales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehiculo_id');
            $table->unsignedBigInteger('usuario_id');
            $table->dateTime('fecha');
            $table->decimal('horometro_actual', 10, 2)->nullable();
            $table->json('checklist_data'); // Stores the questions and answers
            $table->text('observaciones')->nullable();
            $table->string('estado')->default('aprobado'); // aprobado, rechazado
            $table->timestamps();

            $table->foreign('vehiculo_id')->references('vehiculo_id')->on('vehiculos')->onDelete('cascade');
            $table->foreign('usuario_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checklists_preoperacionales');
    }
};
