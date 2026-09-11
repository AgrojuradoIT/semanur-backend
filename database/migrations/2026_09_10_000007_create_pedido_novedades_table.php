<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_novedades', function (Blueprint $table): void {
            $table->id('novedad_id');
            $table->foreignId('pedido_linea_id')->constrained('pedido_inventario_lineas', 'linea_id')->restrictOnDelete();
            $table->foreignId('recepcion_linea_id')->nullable()->constrained('pedido_recepcion_lineas', 'recepcion_linea_id')->restrictOnDelete();
            $table->string('tipo', 60);
            $table->text('motivo');
            $table->decimal('esperado_snapshot', 10, 2)->nullable();
            $table->decimal('contado_snapshot', 10, 2)->nullable();
            $table->decimal('aptos_snapshot', 10, 2)->nullable();
            $table->string('estado', 30)->default('abierta');
            $table->foreignId('responsable_empleado_id')->nullable()->constrained('empleados', 'id')->restrictOnDelete();
            $table->foreignId('documento_id')->nullable()->constrained('pedido_documentos', 'documento_id')->restrictOnDelete();
            $table->timestamp('abierta_at')->nullable();
            $table->timestamp('cerrada_at')->nullable();
            $table->timestamps();

            $table->index(['pedido_linea_id', 'estado'], 'pedido_novedades_linea_estado_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: inventory order evidence must be retained. Restore a verified backup for rollback.',
        );
    }
};
