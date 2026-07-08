<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registros_combustible', function (Blueprint $table) {
            $table->string('tipo_combustible', 20)->default('gasolina')->after('tipo_destino');
        });
    }

    public function down(): void
    {
        Schema::table('registros_combustible', function (Blueprint $table) {
            $table->dropColumn('tipo_combustible');
        });
    }
};
