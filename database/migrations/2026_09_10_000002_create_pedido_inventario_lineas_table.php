<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_inventario_lineas', function (Blueprint $table): void {
            $table->id('linea_id');
            $table->foreignId('pedido_id')->constrained('pedidos_inventario', 'pedido_id')->restrictOnDelete();
            $table->unsignedSmallInteger('orden');
            $table->string('sku_raw', 120)->nullable();
            $table->string('sku_confirmado', 120)->nullable();
            $table->text('descripcion_raw')->nullable();
            $table->string('descripcion_confirmada')->nullable();
            $table->decimal('cantidad_esperada', 10, 2)->nullable();
            $table->string('unidad', 40)->nullable();
            $table->foreignId('producto_id')->nullable()->constrained('productos', 'producto_id')->restrictOnDelete();
            $table->foreignId('categoria_propuesta_id')->nullable()->constrained('categorias', 'categoria_id')->restrictOnDelete();
            $table->decimal('alerta_minima_propuesta', 10, 2)->nullable();
            $table->decimal('precio_costo_confirmado', 14, 2)->nullable();
            $table->string('moneda', 3)->nullable();
            $table->string('base_precio', 60)->nullable();
            $table->string('estado', 40)->default('pendiente');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('esperado_congelado_at')->nullable();
            $table->timestamps();

            $table->unique(['pedido_id', 'orden'], 'pedido_lineas_pedido_orden_unique');
            $table->index('estado', 'pedido_lineas_estado_idx');
            $table->index('sku_confirmado', 'pedido_lineas_sku_idx');
        });

        // SQLite no soporta ALTER TABLE ADD CONSTRAINT; la regla también se
        // valida en el dominio y en el paquete SQL de producción.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `pedido_inventario_lineas` '
                .'ADD CONSTRAINT `pedido_lineas_cantidad_esperada_check` '
                .'CHECK (`cantidad_esperada` IS NULL OR `cantidad_esperada` > 0)',
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
