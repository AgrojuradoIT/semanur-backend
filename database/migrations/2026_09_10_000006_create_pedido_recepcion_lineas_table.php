<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_recepcion_lineas', function (Blueprint $table): void {
            $table->id('recepcion_linea_id');
            $table->foreignId('recepcion_id')->constrained('pedido_recepciones', 'recepcion_id')->restrictOnDelete();
            $table->foreignId('pedido_linea_id')->constrained('pedido_inventario_lineas', 'linea_id')->restrictOnDelete();
            $table->decimal('conteo_total', 10, 2)->nullable();
            $table->decimal('conteo_aptos', 10, 2)->nullable();
            $table->string('estado_condicion', 40)->nullable();
            $table->text('motivo')->nullable();
            $table->unsignedInteger('observacion_version')->default(1);
            $table->boolean('count_confirmed')->default(false);
            $table->foreignId('actor_user_id')->nullable()->constrained('users', 'id')->restrictOnDelete();
            $table->foreignId('receptor_empleado_id')->nullable()->constrained('empleados', 'id')->restrictOnDelete();
            $table->decimal('esperado_snapshot', 10, 2)->nullable();
            $table->json('snapshots')->nullable();
            $table->timestamps();

            $table->unique(['recepcion_id', 'pedido_linea_id'], 'pedido_recepcion_lineas_unique');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `pedido_recepcion_lineas` '
                .'ADD CONSTRAINT `pedido_recepcion_lineas_rango_check` '
                .'CHECK (`conteo_aptos` IS NULL OR `conteo_total` IS NULL '
                .'OR (`conteo_aptos` >= 0 AND `conteo_aptos` <= `conteo_total`))',
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
