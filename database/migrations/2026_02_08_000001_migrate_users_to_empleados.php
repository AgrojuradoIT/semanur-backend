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
        // 1. Eliminar Foreign Keys existentes hacia 'users'
        Schema::table('vehiculos', function (Blueprint $table) {
            // Nota: Laravel concatena tabla_columna_foreign por defecto
            // Si fallan los nombres, habría que verificar la DB
            $table->dropForeign(['operador_asignado_id']); 
            $table->dropForeign(['mecanico_asignado_id']);
        });

        Schema::table('orden_trabajos', function (Blueprint $table) {
            $table->dropForeign(['mecanico_asignado_id']);
        });

        if (Schema::hasTable('programacion')) {
            Schema::table('programacion', function (Blueprint $table) {
                $table->dropForeign(['empleado_id']);
            });
        }

        // 2. Migrar Datos de Usuarios a Empleados
        $users = DB::table('users')->get();

        foreach ($users as $user) {
            // Separar nombres
            $parts = explode(' ', $user->name, 2);
            $nombres = $parts[0];
            $apellidos = $parts[1] ?? '';

            // Insertar Empleado
            $empleadoId = DB::table('empleados')->insertGetId([
                'nombres' => $nombres,
                'apellidos' => $apellidos,
                'telefono' => $user->phone ?? null,
                'licencia_conduccion' => $user->license_number ?? null,
                'cargo' => $user->cargo ?? null,
                'dependencia' => $user->dependencia ?? null,
                'user_id' => $user->id, // Vinculamos al usuario actual
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Actualizar Referencias (IDs) en tablas operativas
            // El ID que estaba guardado era el user_id. Lo cambiamos por empleado_id.
            
            DB::table('vehiculos')
                ->where('operador_asignado_id', $user->id)
                ->update(['operador_asignado_id' => $empleadoId]);

            DB::table('vehiculos')
                ->where('mecanico_asignado_id', $user->id)
                ->update(['mecanico_asignado_id' => $empleadoId]);

            DB::table('orden_trabajos')
                ->where('mecanico_asignado_id', $user->id)
                ->update(['mecanico_asignado_id' => $empleadoId]);

            if (Schema::hasTable('programacion')) {
                DB::table('programacion')
                    ->where('empleado_id', $user->id)
                    ->update(['empleado_id' => $empleadoId]);
            }
        }

        // 4. Crear Nuevas Foreign Keys hacia 'empleados'
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->foreign('operador_asignado_id')
                  ->references('id')->on('empleados')
                  ->nullOnDelete();
                  
            $table->foreign('mecanico_asignado_id')
                  ->references('id')->on('empleados')
                  ->nullOnDelete();
        });

        Schema::table('orden_trabajos', function (Blueprint $table) {
            $table->foreign('mecanico_asignado_id')
                  ->references('id')->on('empleados')
                  ->nullOnDelete();
        });

        if (Schema::hasTable('programacion')) {
            Schema::table('programacion', function (Blueprint $table) {
                $table->foreign('empleado_id')
                      ->references('id')->on('empleados')
                      ->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Eliminar FKs a empleados
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->dropForeign(['operador_asignado_id']);
            $table->dropForeign(['mecanico_asignado_id']);
        });

        Schema::table('orden_trabajos', function (Blueprint $table) {
            $table->dropForeign(['mecanico_asignado_id']);
        });

        // 2. Restaurar FKs a users (NOTA: Los datos de IDs quedarán inconsistentes 
        // porque ahora apuntan a empleado_id, no user_id. Restaurar datos es complejo aquí)
        
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->foreign('operador_asignado_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('mecanico_asignado_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('orden_trabajos', function (Blueprint $table) {
            $table->foreign('mecanico_asignado_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
