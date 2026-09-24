<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_autorizaciones', function (Blueprint $table): void {
            $table->id('autorizacion_id');
            $table->foreignId('novedad_id')->constrained('pedido_novedades', 'novedad_id')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users', 'id')->restrictOnDelete();
            $table->foreignId('empleado_id')->nullable()->constrained('empleados', 'id')->restrictOnDelete();
            $table->string('rol_snapshot', 60)->nullable();
            $table->decimal('cantidad_autorizada', 10, 2);
            $table->unsignedInteger('order_version');
            $table->unsignedInteger('line_version');
            $table->unsignedInteger('count_revision');
            $table->char('contexto_hash', 64);
            $table->decimal('diferencia_cantidad', 10, 2)->nullable();
            $table->decimal('importe', 14, 2)->nullable();
            $table->decimal('precio_unitario_confirmado', 14, 2)->nullable();
            $table->string('moneda', 3)->nullable();
            $table->string('base_precio', 60)->nullable();
            $table->text('motivo');
            $table->text('aceptacion_responsabilidad');
            $table->timestamp('aceptada_at')->nullable();
            $table->string('estado', 30)->default('vigente');
            $table->foreignId('consumida_por_recepcion_id')->nullable()->constrained('pedido_recepciones', 'recepcion_id')->restrictOnDelete();
            $table->timestamp('consumida_at')->nullable();
            $table->timestamps();

            $table->index(['novedad_id', 'estado'], 'pedido_autorizaciones_novedad_estado_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `pedido_autorizaciones` '
                .'ADD CONSTRAINT `pedido_autorizaciones_cantidad_check` '
                .'CHECK (`cantidad_autorizada` >= 0)',
            );
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: inventory order evidence must be retained. Restore a verified backup for rollback.',
        );
    }
};
