<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos_inventario', function (Blueprint $table): void {
            $table->id('pedido_id');
            $table->uuid('uuid')->unique();
            $table->string('numero', 40)->nullable()->unique();
            $table->string('proveedor_nombre_raw')->nullable();
            $table->string('proveedor_nombre_confirmado')->nullable();
            $table->string('proveedor_clave', 120)->nullable();
            $table->string('referencia_factura', 120)->nullable();
            $table->string('clave_documental_normalizada', 190)->nullable()->unique();
            $table->foreignId('bodega_id')->nullable()->constrained('bodegas', 'bodega_id')->restrictOnDelete();
            $table->string('estado', 40)->default('borrador');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users', 'id')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users', 'id')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_motivo')->nullable();
            $table->timestamps();

            $table->index('estado', 'pedidos_inventario_estado_idx');
            $table->index('proveedor_nombre_confirmado', 'pedidos_inventario_proveedor_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: inventory order evidence must be retained. Restore a verified backup for rollback.',
        );
    }
};
