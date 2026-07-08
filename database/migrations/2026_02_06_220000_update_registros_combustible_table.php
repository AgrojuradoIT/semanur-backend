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
        Schema::table('registros_combustible', function (Blueprint $table) {
            $table->unsignedBigInteger('vehiculo_id')->nullable()->change();
            $table->foreignId('empleado_id')->nullable()->after('vehiculo_id')->constrained('users', 'id')->cascadeOnDelete();
            $table->string('tercero_nombre')->nullable()->after('empleado_id');
            $table->string('tipo_destino')->default('vehiculo')->after('tercero_nombre'); // 'vehiculo', 'empleado', 'tercero'
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registros_combustible', function (Blueprint $table) {
            // Revert changes is tricky because of nullable change and data. 
            // We will just drop new columns and assume vehicle_id was not null.
            $table->dropForeign(['empleado_id']);
            $table->dropColumn(['empleado_id', 'tercero_nombre', 'tipo_destino']);
            $table->unsignedBigInteger('vehiculo_id')->nullable(false)->change();
        });
    }
};
