<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20);           // e.g. 0.5.2
            $table->string('min_version', 20)         // versión mínima soportada
                  ->default('0.1.0');
            $table->string('apk_path');                // ruta al archivo en storage
            $table->text('release_notes')->nullable(); // notas del release
            $table->boolean('force_update')            // ¿forzar actualización?
                  ->default(false);
            $table->boolean('is_active')               // ¿es la versión activa?
                  ->default(false);
            $table->foreignId('created_by')            // quién subió esta versión
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            $table->unique('version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
