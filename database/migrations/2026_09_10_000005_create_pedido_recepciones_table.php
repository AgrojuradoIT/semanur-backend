<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_recepciones', function (Blueprint $table): void {
            $table->id('recepcion_id');
            $table->foreignId('pedido_id')->constrained('pedidos_inventario', 'pedido_id')->restrictOnDelete();
            $table->string('estado', 30)->default('borrador');
            $table->foreignId('actor_user_id')->nullable()->constrained('users', 'id')->restrictOnDelete();
            $table->foreignId('receptor_empleado_id')->nullable()->constrained('empleados', 'id')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('clave_operacion')->nullable()->unique();
            $table->timestamp('confirmada_at')->nullable();
            $table->timestamps();

            $table->index(['pedido_id', 'estado'], 'pedido_recepciones_pedido_estado_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: inventory order evidence must be retained. Restore a verified backup for rollback.',
        );
    }
};
