<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // The earlier users-to-empleados migration already establishes the
            // correct FK in the in-memory test schema.
            return;
        }

        $foreignKey = $this->foreignKey();

        if (($foreignKey->referenced_table_name ?? null) === 'empleados') {
            if (($foreignKey->referenced_column_name ?? null) !== 'id') {
                throw new RuntimeException('Unexpected mechanic foreign-key column on empleados.');
            }

            if (in_array($foreignKey->delete_rule ?? null, ['RESTRICT', 'NO ACTION'], true)) {
                return;
            }

            if (($foreignKey->delete_rule ?? null) === 'CASCADE') {
                throw new RuntimeException(
                    'Mechanic assignments use CASCADE. Investigate possible historical assignment loss before changing the constraint.',
                );
            }

            $this->replaceForeignKey((string) $foreignKey->constraint_name);

            return;
        }

        if (($foreignKey->referenced_table_name ?? null) === 'users') {
            $assignedOrders = DB::table('orden_trabajos')
                ->whereNotNull('mecanico_asignado_id')
                ->count();
            $missingMappings = DB::table('orden_trabajos as ot')
                ->leftJoin('empleados as e', 'e.user_id', '=', 'ot.mecanico_asignado_id')
                ->whereNotNull('ot.mecanico_asignado_id')
                ->whereNull('e.id')
                ->count();
            $ambiguousMappings = DB::table('empleados')
                ->whereNotNull('user_id')
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            if ($assignedOrders > 0) {
                throw new RuntimeException(
                    "Automatic mechanic remapping is disabled to prevent data loss ({$assignedOrders} assigned, {$missingMappings} missing, {$ambiguousMappings} ambiguous). Use the reviewed SQL baseline procedure.",
                );
            }

            // A fresh/empty installation has no identity values to remap.
            $this->replaceForeignKey((string) $foreignKey->constraint_name);

            return;
        } elseif ($foreignKey === null) {
            $invalidEmployees = DB::table('orden_trabajos as ot')
                ->leftJoin('empleados as e', 'e.id', '=', 'ot.mecanico_asignado_id')
                ->whereNotNull('ot.mecanico_asignado_id')
                ->whereNull('e.id')
                ->count();

            if ($invalidEmployees > 0) {
                throw new RuntimeException(
                    "Cannot infer assignment identity without a foreign key: {$invalidEmployees} values are not employee IDs.",
                );
            }
        } else {
            throw new RuntimeException(
                'Unexpected mechanic foreign-key target: '.($foreignKey->referenced_table_name ?? 'unknown'),
            );
        }

        Schema::table('orden_trabajos', function (Blueprint $table): void {
            $table->foreign('mecanico_asignado_id')
                ->references('id')
                ->on('empleados')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: mechanic identity mappings must be retained. Restore a verified backup for rollback.',
        );
    }

    private function foreignKey(): ?object
    {
        $foreignKeys = DB::table('information_schema.key_column_usage as kcu')
            ->join('information_schema.referential_constraints as rc', function ($join): void {
                $join->on('rc.constraint_schema', '=', 'kcu.constraint_schema')
                    ->on('rc.constraint_name', '=', 'kcu.constraint_name')
                    ->on('rc.table_name', '=', 'kcu.table_name');
            })
            ->where('kcu.table_schema', DB::getDatabaseName())
            ->where('kcu.table_name', 'orden_trabajos')
            ->where('kcu.column_name', 'mecanico_asignado_id')
            ->whereNotNull('kcu.referenced_table_name')
            ->select([
                'kcu.constraint_name',
                'kcu.referenced_table_name',
                'kcu.referenced_column_name',
                'rc.delete_rule',
            ])
            ->get();

        if ($foreignKeys->count() > 1) {
            throw new RuntimeException('Mechanic assignment has multiple foreign keys; manual review is required.');
        }

        return $foreignKeys->first();
    }

    private function replaceForeignKey(string $constraint): void
    {
        $quoted = str_replace('`', '``', $constraint);
        DB::statement(
            "ALTER TABLE `orden_trabajos` DROP FOREIGN KEY `{$quoted}`, "
            .'ADD CONSTRAINT `ot_mecanico_restrict_fk` '
            .'FOREIGN KEY (`mecanico_asignado_id`) REFERENCES `empleados` (`id`) ON DELETE RESTRICT'
        );
    }
};
