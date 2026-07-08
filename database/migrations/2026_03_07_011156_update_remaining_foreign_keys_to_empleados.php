<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Idempotent: safe to run even if tables were partially or fully migrated.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // 1. Prestamos Herramientas
        $this->migratePrestamosHerramientas();

        // 2. Checklists Preoperacionales
        $this->migrateChecklistsPreoperacionales();

        // 3. Respuestas Lista Chequeo
        $this->migrateRespuestasListaChequeo();
    }

    private function migratePrestamosHerramientas(): void
    {
        // Already migrated if mecanico_id references empleados
        $fk = DB::select("
            SELECT REFERENCED_TABLE_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'prestamos_herramientas'
            AND COLUMN_NAME = 'mecanico_id'
            AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1
        ");

        if (!empty($fk) && $fk[0]->REFERENCED_TABLE_NAME === 'empleados') {
            return; // Already done
        }

        // Full migration: user_id -> empleado_id
        Schema::table('prestamos_herramientas', function (Blueprint $table) {
            $table->unsignedBigInteger('temp_mecanico_id')->nullable()->after('mecanico_id');
        });

        foreach (DB::table('prestamos_herramientas')->get() as $p) {
            $empleado = DB::table('empleados')->where('user_id', $p->mecanico_id)->first();
            if ($empleado) {
                DB::table('prestamos_herramientas')->where('prestamo_id', $p->prestamo_id)
                    ->update(['temp_mecanico_id' => $empleado->id]);
            } else {
                DB::table('prestamos_herramientas')->where('prestamo_id', $p->prestamo_id)->delete();
            }
        }

        Schema::table('prestamos_herramientas', function (Blueprint $table) {
            $table->dropForeign(['mecanico_id']);
            $table->dropColumn('mecanico_id');
        });

        Schema::table('prestamos_herramientas', function (Blueprint $table) {
            $table->renameColumn('temp_mecanico_id', 'mecanico_id');
        });

        Schema::table('prestamos_herramientas', function (Blueprint $table) {
            $table->unsignedBigInteger('mecanico_id')->nullable(false)->change();
            $table->foreign('mecanico_id')->references('id')->on('empleados')->cascadeOnDelete();
        });
    }

    private function migrateChecklistsPreoperacionales(): void
    {
        // Already migrated if empleado_id exists and references empleados
        if (Schema::hasColumn('checklists_preoperacionales', 'empleado_id')) {
            $fk = DB::select("
                SELECT REFERENCED_TABLE_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'checklists_preoperacionales'
                AND COLUMN_NAME = 'empleado_id'
                AND REFERENCED_TABLE_NAME IS NOT NULL
                LIMIT 1
            ");

            if (!empty($fk) && $fk[0]->REFERENCED_TABLE_NAME === 'empleados') {
                return; // Already done
            }

            // Column exists but FK missing - just add it
            Schema::table('checklists_preoperacionales', function (Blueprint $table) {
                $table->foreign('empleado_id')->references('id')->on('empleados')->cascadeOnDelete();
            });
            return;
        }

        // Full migration: usuario_id -> empleado_id
        Schema::table('checklists_preoperacionales', function (Blueprint $table) {
            $table->unsignedBigInteger('empleado_id')->nullable()->after('usuario_id');
        });

        foreach (DB::table('checklists_preoperacionales')->get() as $c) {
            $empleado = DB::table('empleados')->where('user_id', $c->usuario_id)->first();
            if ($empleado) {
                DB::table('checklists_preoperacionales')->where('id', $c->id)
                    ->update(['empleado_id' => $empleado->id]);
            } else {
                DB::table('checklists_preoperacionales')->where('id', $c->id)->delete();
            }
        }

        Schema::table('checklists_preoperacionales', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
            $table->dropColumn('usuario_id');
        });

        Schema::table('checklists_preoperacionales', function (Blueprint $table) {
            $table->unsignedBigInteger('empleado_id')->nullable(false)->change();
            $table->foreign('empleado_id')->references('id')->on('empleados')->cascadeOnDelete();
        });
    }

    private function migrateRespuestasListaChequeo(): void
    {
        // Already migrated if operador_id references empleados
        $fk = DB::select("
            SELECT REFERENCED_TABLE_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'respuestas_lista_chequeo'
            AND COLUMN_NAME = 'operador_id'
            AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1
        ");

        if (!empty($fk) && $fk[0]->REFERENCED_TABLE_NAME === 'empleados') {
            // Clean up temp column if it exists from a partial run
            if (Schema::hasColumn('respuestas_lista_chequeo', 'temp_operador_id')) {
                Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
                    $table->dropColumn('temp_operador_id');
                });
            }
            return; // Already done
        }

        // Handle partial migration: temp_operador_id exists but not yet renamed
        if (Schema::hasColumn('respuestas_lista_chequeo', 'temp_operador_id')) {
            // Copy temp data to main column row by row
            foreach (DB::table('respuestas_lista_chequeo')->whereNotNull('temp_operador_id')->get() as $r) {
                DB::table('respuestas_lista_chequeo')->where('id', $r->id)
                    ->update(['operador_id' => $r->temp_operador_id]);
            }

            Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
                $table->dropColumn('temp_operador_id');
            });

            Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
                $table->unsignedBigInteger('operador_id')->nullable(false)->change();
                $table->foreign('operador_id')->references('id')->on('empleados')->cascadeOnDelete();
            });
            return;
        }

        // Full migration: user_id -> empleado_id
        Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->unsignedBigInteger('temp_operador_id')->nullable()->after('operador_id');
        });

        foreach (DB::table('respuestas_lista_chequeo')->get() as $r) {
            $empleado = DB::table('empleados')->where('user_id', $r->operador_id)->first();
            if ($empleado) {
                DB::table('respuestas_lista_chequeo')->where('id', $r->id)
                    ->update(['temp_operador_id' => $empleado->id]);
            } else {
                DB::table('respuestas_lista_chequeo')->where('id', $r->id)->delete();
            }
        }

        Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->dropForeign(['operador_id']);
            $table->dropColumn('operador_id');
        });

        Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->renameColumn('temp_operador_id', 'operador_id');
        });

        Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->unsignedBigInteger('operador_id')->nullable(false)->change();
            $table->foreign('operador_id')->references('id')->on('empleados')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Revert Respuestas
        Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->dropForeign(['operador_id']);
            $table->unsignedBigInteger('temp_user_id')->nullable()->after('operador_id');
        });

        foreach (DB::table('respuestas_lista_chequeo')->get() as $r) {
            $user_id = DB::table('empleados')->where('id', $r->operador_id)->value('user_id');
            if ($user_id) {
                DB::table('respuestas_lista_chequeo')->where('id', $r->id)->update(['temp_user_id' => $user_id]);
            }
        }

        Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->dropColumn('operador_id');
        });

        Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->renameColumn('temp_user_id', 'operador_id');
        });

        Schema::table('respuestas_lista_chequeo', function (Blueprint $table) {
            $table->unsignedBigInteger('operador_id')->nullable(false)->change();
            $table->foreign('operador_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Revert Checklist
        Schema::table('checklists_preoperacionales', function (Blueprint $table) {
            $table->dropForeign(['empleado_id']);
            $table->unsignedBigInteger('usuario_id')->nullable()->after('empleado_id');
        });

        foreach (DB::table('checklists_preoperacionales')->get() as $c) {
            $user_id = DB::table('empleados')->where('id', $c->empleado_id)->value('user_id');
            if ($user_id) {
                DB::table('checklists_preoperacionales')->where('id', $c->id)->update(['usuario_id' => $user_id]);
            }
        }

        Schema::table('checklists_preoperacionales', function (Blueprint $table) {
            $table->dropColumn('empleado_id');
            $table->unsignedBigInteger('usuario_id')->nullable(false)->change();
            $table->foreign('usuario_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Revert Prestamos
        Schema::table('prestamos_herramientas', function (Blueprint $table) {
            $table->dropForeign(['mecanico_id']);
            $table->unsignedBigInteger('temp_user_id')->nullable()->after('mecanico_id');
        });

        foreach (DB::table('prestamos_herramientas')->get() as $p) {
            $user_id = DB::table('empleados')->where('id', $p->mecanico_id)->value('user_id');
            if ($user_id) {
                DB::table('prestamos_herramientas')->where('prestamo_id', $p->prestamo_id)->update(['temp_user_id' => $user_id]);
            }
        }

        Schema::table('prestamos_herramientas', function (Blueprint $table) {
            $table->dropColumn('mecanico_id');
        });

        Schema::table('prestamos_herramientas', function (Blueprint $table) {
            $table->renameColumn('temp_user_id', 'mecanico_id');
        });

        Schema::table('prestamos_herramientas', function (Blueprint $table) {
            $table->unsignedBigInteger('mecanico_id')->nullable(false)->change();
            $table->foreign('mecanico_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
