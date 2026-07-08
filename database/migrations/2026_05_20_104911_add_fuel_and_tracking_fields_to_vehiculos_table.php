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
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->string('tipo_combustible')->nullable()->default('acpm')->after('tipo');
            $table->string('metodo_seguimiento')->nullable()->default('kilometraje')->after('tipo_combustible');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->dropColumn(['tipo_combustible', 'metodo_seguimiento']);
        });
    }
};
