<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_operaciones', function (Blueprint $table): void {
            $table->id('operacion_id');
            $table->foreignId('actor_id')->constrained('users', 'id')->restrictOnDelete();
            $table->string('command', 80);
            $table->uuid('client_operation_id');
            $table->char('canonical_request_hash', 64);
            $table->string('estado', 30)->default('completada');
            $table->string('referencia', 190)->nullable();
            $table->json('resultado')->nullable();
            $table->timestamp('completada_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['actor_id', 'command', 'client_operation_id'],
                'pedido_operaciones_actor_command_operation_unique',
            );
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: inventory order evidence must be retained. Restore a verified backup for rollback.',
        );
    }
};
