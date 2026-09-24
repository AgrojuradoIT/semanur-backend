<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_alertas', function (Blueprint $table): void {
            $table->id('alerta_id');
            $table->uuid('event_uuid');
            $table->foreignId('destinatario_user_id')->constrained('users', 'id')->restrictOnDelete();
            $table->foreignId('pedido_id')->nullable()->constrained('pedidos_inventario', 'pedido_id')->restrictOnDelete();
            $table->string('tipo', 80);
            $table->timestamp('leida_at')->nullable();
            $table->timestamps();

            $table->unique(['event_uuid', 'destinatario_user_id'], 'pedido_alertas_evento_destinatario_unique');
            $table->index(['destinatario_user_id', 'leida_at'], 'pedido_alertas_destinatario_leida_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: inventory order evidence must be retained. Restore a verified backup for rollback.',
        );
    }
};
