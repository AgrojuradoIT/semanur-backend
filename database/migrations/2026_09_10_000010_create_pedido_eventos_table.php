<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_eventos', function (Blueprint $table): void {
            $table->id('evento_id');
            $table->uuid('event_uuid')->unique();
            $table->foreignId('pedido_id')->nullable()->constrained('pedidos_inventario', 'pedido_id')->restrictOnDelete();
            $table->foreignId('pedido_linea_id')->nullable()->constrained('pedido_inventario_lineas', 'linea_id')->restrictOnDelete();
            $table->string('event_type', 80);
            $table->foreignId('actor_user_id')->nullable()->constrained('users', 'id')->restrictOnDelete();
            $table->foreignId('actor_empleado_id')->nullable()->constrained('empleados', 'id')->restrictOnDelete();
            $table->string('actor_rol_snapshot', 60)->nullable();
            $table->json('datos_antes')->nullable();
            $table->json('datos_despues')->nullable();
            $table->uuid('command_id')->nullable();
            $table->unsignedInteger('revision')->nullable();
            $table->string('estado_despacho', 30)->default('pendiente');
            $table->unsignedTinyInteger('intentos_despacho')->default(0);
            $table->timestamp('despachado_at')->nullable();
            $table->timestamps();

            $table->index(['pedido_id', 'created_at'], 'pedido_eventos_pedido_fecha_idx');
            $table->index('event_type', 'pedido_eventos_tipo_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: inventory order evidence must be retained. Restore a verified backup for rollback.',
        );
    }
};
