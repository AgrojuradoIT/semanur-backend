<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_ocr_intentos', function (Blueprint $table): void {
            $table->id('intento_id');
            $table->foreignId('documento_id')->constrained('pedido_documentos', 'documento_id')->restrictOnDelete();
            $table->unsignedInteger('documento_revision')->default(1);
            $table->string('proveedor', 60);
            $table->string('proveedor_version', 60)->nullable();
            $table->string('mapper_version', 60)->nullable();
            $table->string('clave_trabajo', 190)->unique();
            $table->string('estado', 30)->default('pendiente');
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedBigInteger('fencing_token')->default(0);
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->unsignedTinyInteger('max_intentos')->default(3);
            $table->json('resultado')->nullable();
            $table->string('referencia_privada', 500)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_mensaje')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['documento_id', 'estado'], 'pedido_ocr_documento_estado_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: inventory order evidence must be retained. Restore a verified backup for rollback.',
        );
    }
};
