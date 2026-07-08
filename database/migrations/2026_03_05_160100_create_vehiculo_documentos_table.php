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
        Schema::create('vehiculo_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehiculo_id')->constrained('vehiculos', 'vehiculo_id')->onDelete('cascade');
            $table->enum('tipo', ['soat', 'tecnomecanica']);
            $table->date('fecha_inicio');
            $table->date('fecha_vencimiento');
            $table->string('compania')->nullable();
            $table->string('certificado_pdf')->nullable(); // Ruta al archivo PDF
            $table->enum('estado', ['activo', 'vencido', 'renovado'])->default('activo');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehiculo_documentos');
    }
};