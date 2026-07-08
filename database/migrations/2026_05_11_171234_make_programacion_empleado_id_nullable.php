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
        Schema::table('programacion', function (Blueprint $table) {
            $table->dropForeign(['empleado_id']);
            $table->unsignedBigInteger('empleado_id')->nullable()->change();
            $table->foreign('empleado_id')
                ->references('id')
                ->on('empleados')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('programacion', function (Blueprint $table) {
            $table->dropForeign(['empleado_id']);
            $table->foreign('empleado_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
