<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_inventario_lineas', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_linea_id')->nullable()->after('pedido_id');
            $table->foreign('parent_linea_id', 'pedido_lineas_parent_restrict_fk')
                ->references('linea_id')
                ->on('pedido_inventario_lineas')
                ->restrictOnDelete();
            $table->index('parent_linea_id', 'pedido_lineas_parent_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: the backorder lineage link to pedido_inventario_lineas must be retained. Restore a verified backup for rollback.',
        );
    }
};
