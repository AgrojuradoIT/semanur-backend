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
        Schema::create('media', function (Blueprint $table) {
            $table->id();

            // Contexto lógico de la foto
            $table->string('module'); // taller, flota, inventario, etc.
            $table->string('entity_type'); // orden_trabajo, vehiculo, producto, etc.
            $table->unsignedBigInteger('entity_id');

            // Almacenamiento físico
            $table->string('disk')->default('public'); // public, s3, etc.
            $table->string('path'); // ruta relativa dentro del disk (media/...)
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable(); // tamaño en bytes

            // Auditoría básica
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Índices para consultas rápidas por entidad
            $table->index(['module', 'entity_type', 'entity_id'], 'media_entity_index');
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};

