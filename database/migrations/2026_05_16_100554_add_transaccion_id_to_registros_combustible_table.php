<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registros_combustible', function (Blueprint $table) {
            $table->unsignedBigInteger('transaccion_id')->nullable()->after('registro_id');
            $table->foreign('transaccion_id')->references('transaccion_id')->on('transaccion_inventarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registros_combustible', function (Blueprint $table) {
            $table->dropForeign(['transaccion_id']);
            $table->dropColumn('transaccion_id');
        });
    }
};
