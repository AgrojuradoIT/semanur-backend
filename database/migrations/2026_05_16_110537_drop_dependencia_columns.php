<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn('dependencia');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dependencia');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('dependencia')->nullable()->after('cargo');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('dependencia')->nullable()->after('cargo');
        });
    }
};
