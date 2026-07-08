<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaccion_inventarios', function (Blueprint $table) {
            $table->unsignedBigInteger('bodega_id')->nullable()->after('producto_id');
            $table->foreign('bodega_id')->references('bodega_id')->on('bodegas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transaccion_inventarios', function (Blueprint $table) {
            $table->dropForeign(['bodega_id']);
            $table->dropColumn('bodega_id');
        });
    }
};
