<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaccion_inventarios', function (Blueprint $table): void {
            $table->unsignedBigInteger('pedido_linea_id')->nullable()->after('transaccion_referencia_id');
            $table->foreign('pedido_linea_id', 'trans_inv_pedido_linea_restrict_fk')
                ->references('linea_id')
                ->on('pedido_inventario_lineas')
                ->restrictOnDelete();
            $table->unique('pedido_linea_id', 'trans_inv_pedido_linea_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: the unique ledger link to pedido_inventario_lineas must be retained. Restore a verified backup for rollback.',
        );
    }
};
