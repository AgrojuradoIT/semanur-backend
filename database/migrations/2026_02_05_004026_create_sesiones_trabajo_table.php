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
        Schema::create('sesiones_trabajo', function (Blueprint $table) {
            $table->id('sesion_id');
            $table->foreignId('user_id')->constrained('users', 'id');
            $table->foreignId('orden_trabajo_id')->constrained('orden_trabajos', 'orden_trabajo_id');
            $table->dateTime('fecha_inicio');
            $table->dateTime('fecha_fin')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sesiones_trabajo');
    }
};
