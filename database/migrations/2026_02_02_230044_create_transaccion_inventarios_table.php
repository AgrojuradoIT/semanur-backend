<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transaccion_inventarios', function (Blueprint $table) {
            $table->id('transaccion_id');
            $table->foreignId('producto_id')->constrained('productos', 'producto_id')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users', 'id')->nullOnDelete();
            $table->string('transaccion_tipo'); // entrada, salida
            $table->decimal('transaccion_cantidad', 10, 2);
            $table->string('transaccion_motivo'); // compra, ajuste, consumo_ot, devolucion
            $table->string('transaccion_referencia_type')->nullable();
            $table->unsignedBigInteger('transaccion_referencia_id')->nullable();
            $table->index(['transaccion_referencia_type', 'transaccion_referencia_id'], 'trans_ref_idx');
            $table->text('transaccion_notas')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaccion_inventarios');
    }
};
