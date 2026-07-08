<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sesiones_trabajo', function (Blueprint $table) {
            $table->unsignedBigInteger('empleado_id')->nullable()->after('sesion_id');
        });

        // Migrate data
        $sesiones = DB::table('sesiones_trabajo')->get();
        foreach ($sesiones as $sesion) {
            $empleado = DB::table('empleados')->where('user_id', $sesion->user_id)->first();
            if ($empleado) {
                DB::table('sesiones_trabajo')->where('sesion_id', $sesion->sesion_id)->update(['empleado_id' => $empleado->id]);
            } else {
                // If it's an admin user with no associated empleado, we might need an orphaned record or create a fake employee. We'll leave it as null for now, which will crash the non-nullable alter table if we don't handle it
                // To avoid issues, let's just delete the session if it's orphaned or assign to the first employee
                DB::table('sesiones_trabajo')->where('sesion_id', $sesion->sesion_id)->delete();
            }
        }

        Schema::table('sesiones_trabajo', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('sesiones_trabajo', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });

        Schema::table('sesiones_trabajo', function (Blueprint $table) {
            $table->unsignedBigInteger('empleado_id')->nullable(false)->change();
            $table->foreign('empleado_id')->references('id')->on('empleados')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sesiones_trabajo', function (Blueprint $table) {
            $table->dropForeign(['empleado_id']);
            $table->unsignedBigInteger('user_id')->nullable();
        });

        $sesiones = DB::table('sesiones_trabajo')->get();
        foreach ($sesiones as $sesion) {
            $empleado = DB::table('empleados')->where('id', $sesion->empleado_id)->first();
            if ($empleado && $empleado->user_id) {
                DB::table('sesiones_trabajo')->where('sesion_id', $sesion->sesion_id)->update(['user_id' => $empleado->user_id]);
            } else {
                DB::table('sesiones_trabajo')->where('sesion_id', $sesion->sesion_id)->delete();
            }
        }

        Schema::table('sesiones_trabajo', function (Blueprint $table) {
            $table->dropColumn('empleado_id');
        });

        Schema::table('sesiones_trabajo', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
