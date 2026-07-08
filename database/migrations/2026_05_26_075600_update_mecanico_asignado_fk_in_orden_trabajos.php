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
        // First we need to map the existing user_ids to empleado_ids
        $ordenes = DB::table('orden_trabajos')->whereNotNull('mecanico_asignado_id')->get();
        foreach ($ordenes as $orden) {
            $empleado = DB::table('empleados')->where('user_id', $orden->mecanico_asignado_id)->first();
            if ($empleado) {
                DB::table('orden_trabajos')
                    ->where('orden_trabajo_id', $orden->orden_trabajo_id)
                    ->update(['mecanico_asignado_id' => $empleado->id]);
            } else {
                DB::table('orden_trabajos')
                    ->where('orden_trabajo_id', $orden->orden_trabajo_id)
                    ->update(['mecanico_asignado_id' => null]);
            }
        }

        Schema::table('orden_trabajos', function (Blueprint $table) {
            // Drop the old foreign key constraint
            $table->dropForeign(['mecanico_asignado_id']);
            
            // Add the new foreign key constraint pointing to empleados
            $table->foreign('mecanico_asignado_id')->references('id')->on('empleados')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orden_trabajos', function (Blueprint $table) {
            $table->dropForeign(['mecanico_asignado_id']);
        });

        // Migrate back from empleado_id to user_id
        $ordenes = DB::table('orden_trabajos')->whereNotNull('mecanico_asignado_id')->get();
        foreach ($ordenes as $orden) {
            $empleado = DB::table('empleados')->where('id', $orden->mecanico_asignado_id)->first();
            if ($empleado && $empleado->user_id) {
                DB::table('orden_trabajos')
                    ->where('orden_trabajo_id', $orden->orden_trabajo_id)
                    ->update(['mecanico_asignado_id' => $empleado->user_id]);
            } else {
                DB::table('orden_trabajos')
                    ->where('orden_trabajo_id', $orden->orden_trabajo_id)
                    ->update(['mecanico_asignado_id' => null]);
            }
        }

        Schema::table('orden_trabajos', function (Blueprint $table) {
            $table->foreign('mecanico_asignado_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
