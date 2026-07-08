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
        Schema::create('empleados', function (Blueprint $table) {
            $table->id();
            $table->string('nombres');
            $table->string('apellidos')->nullable();
            $table->string('documento')->nullable()->unique(); // Cédula/DNI
            $table->string('telefono')->nullable();
            $table->string('direccion')->nullable();
            $table->string('cargo')->nullable(); // Mecánico, Operador, etc.
            $table->string('dependencia')->nullable(); // Área
            
            // Datos de conducción
            $table->string('licencia_conduccion')->nullable();
            $table->string('categoria_licencia')->nullable();
            $table->date('vencimiento_licencia')->nullable();
            
            $table->string('foto_url')->nullable();
            
            // Relación con Usuario de Sistema (Opcional)
            $table->unsignedBigInteger('user_id')->nullable()->unique();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            
            $table->string('estado')->default('activo'); // activo, inactivo
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};
