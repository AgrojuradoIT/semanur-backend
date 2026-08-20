<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaccion_inventarios', function (Blueprint $table): void {
            $table->unsignedBigInteger('reverses_transaction_id')
                ->nullable()
                ->after('transaccion_id');
            $table->unique('reverses_transaction_id', 'transaccion_inventory_reversal_unique');
            $table->foreign('reverses_transaction_id', 'transaccion_inventory_reversal_fk')
                ->references('transaccion_id')
                ->on('transaccion_inventarios')
                ->restrictOnDelete();
        });

        Schema::table('registros_combustible', function (Blueprint $table): void {
            $table->unique('transaccion_id', 'registro_combustible_transaccion_unique');
        });
    }

    public function down(): void
    {
        Schema::table('registros_combustible', function (Blueprint $table): void {
            $table->dropUnique('registro_combustible_transaccion_unique');
        });

        Schema::table('transaccion_inventarios', function (Blueprint $table): void {
            $table->dropForeign('transaccion_inventory_reversal_fk');
            $table->dropUnique('transaccion_inventory_reversal_unique');
            $table->dropColumn('reverses_transaction_id');
        });
    }
};
