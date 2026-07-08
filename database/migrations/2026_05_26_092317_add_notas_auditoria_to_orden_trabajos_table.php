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
        if (!Schema::hasColumn('orden_trabajos', 'notas_auditoria')) {
            Schema::table('orden_trabajos', function (Blueprint $table) {
                $table->text('notas_auditoria')->nullable()->after('foto_evidencia');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orden_trabajos', function (Blueprint $table) {
            //
        });
    }
};
