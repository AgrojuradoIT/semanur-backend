<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_documentos', function (Blueprint $table): void {
            $table->id('documento_id');
            $table->foreignId('pedido_id')->constrained('pedidos_inventario', 'pedido_id')->restrictOnDelete();
            $table->string('rol', 40);
            $table->unsignedInteger('documento_revision')->default(1);
            $table->string('storage_key', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('sha256', 64);
            $table->foreignId('created_by')->nullable()->constrained('users', 'id')->restrictOnDelete();
            $table->foreignId('replaced_document_id')->nullable()->constrained('pedido_documentos', 'documento_id')->restrictOnDelete();
            $table->foreignId('pedido_linea_id')->nullable()->constrained('pedido_inventario_lineas', 'linea_id')->restrictOnDelete();
            $table->timestamps();

            $table->index(['pedido_id', 'rol'], 'pedido_documentos_pedido_rol_idx');
            $table->index('sha256', 'pedido_documentos_sha256_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: inventory order evidence must be retained. Restore a verified backup for rollback.',
        );
    }
};
