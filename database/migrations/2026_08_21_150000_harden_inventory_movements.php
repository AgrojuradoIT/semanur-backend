<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transaccion_inventarios', 'bodega_origen_id')) {
            Schema::table('transaccion_inventarios', function (Blueprint $table): void {
                $table->unsignedBigInteger('bodega_origen_id')->nullable()->after('bodega_id');
            });
        }
        if (! Schema::hasColumn('transaccion_inventarios', 'bodega_destino_id')) {
            Schema::table('transaccion_inventarios', function (Blueprint $table): void {
                $table->unsignedBigInteger('bodega_destino_id')->nullable()->after('bodega_origen_id');
            });
        }
        if (! Schema::hasColumn('transaccion_inventarios', 'client_operation_id')) {
            Schema::table('transaccion_inventarios', function (Blueprint $table): void {
                $table->uuid('client_operation_id')->nullable()->after('usuario_id');
            });
        }
        if (! Schema::hasColumn('transaccion_inventarios', 'client_operation_hash')) {
            Schema::table('transaccion_inventarios', function (Blueprint $table): void {
                $table->char('client_operation_hash', 64)->nullable()->after('client_operation_id');
            });
        }
        if (! Schema::hasColumn('registros_combustible', 'client_operation_id')) {
            Schema::table('registros_combustible', function (Blueprint $table): void {
                $table->uuid('client_operation_id')->nullable()->after('usuario_id');
            });
        }
        if (! Schema::hasColumn('registros_combustible', 'client_operation_hash')) {
            Schema::table('registros_combustible', function (Blueprint $table): void {
                $table->char('client_operation_hash', 64)->nullable()->after('client_operation_id');
            });
        }
        if (! Schema::hasColumn('orden_trabajos', 'created_by_user_id')) {
            Schema::table('orden_trabajos', function (Blueprint $table): void {
                $table->unsignedBigInteger('created_by_user_id')->nullable()->after('orden_trabajo_id');
            });
        }
        if (! Schema::hasColumn('orden_trabajos', 'client_operation_id')) {
            Schema::table('orden_trabajos', function (Blueprint $table): void {
                $table->uuid('client_operation_id')->nullable()->after('created_by_user_id');
            });
        }
        if (! Schema::hasColumn('orden_trabajos', 'client_operation_hash')) {
            Schema::table('orden_trabajos', function (Blueprint $table): void {
                $table->char('client_operation_hash', 64)->nullable()->after('client_operation_id');
            });
        }

        $this->ensureIndex('transaccion_inventarios', 'trans_inv_user_client_op_unique', ['usuario_id', 'client_operation_id'], true, fn (Blueprint $table) => $table->unique(['usuario_id', 'client_operation_id'], 'trans_inv_user_client_op_unique'));
        $this->ensureIndex('transaccion_inventarios', 'trans_inv_created_at_index', ['created_at'], false, fn (Blueprint $table) => $table->index('created_at', 'trans_inv_created_at_index'));
        $this->ensureIndex('registros_combustible', 'fuel_user_client_op_unique', ['usuario_id', 'client_operation_id'], true, fn (Blueprint $table) => $table->unique(['usuario_id', 'client_operation_id'], 'fuel_user_client_op_unique'));
        $this->ensureIndex('orden_trabajos', 'ot_user_client_op_unique', ['created_by_user_id', 'client_operation_id'], true, fn (Blueprint $table) => $table->unique(['created_by_user_id', 'client_operation_id'], 'ot_user_client_op_unique'));

        $this->ensureRestrictForeignKey('transaccion_inventarios', 'bodega_origen_id', 'bodegas', 'bodega_id', 'trans_inv_bodega_origen_fk');
        $this->ensureRestrictForeignKey('transaccion_inventarios', 'bodega_destino_id', 'bodegas', 'bodega_id', 'trans_inv_bodega_destino_fk');
        $this->ensureRestrictForeignKey('orden_trabajos', 'created_by_user_id', 'users', 'id', 'ot_created_by_user_fk');

        $this->ensureCheckConstraint(
            'transaccion_inventarios',
            'trans_inv_operation_pair_check',
            '(client_operation_id IS NULL AND client_operation_hash IS NULL) OR (client_operation_id IS NOT NULL AND client_operation_hash IS NOT NULL AND usuario_id IS NOT NULL)',
        );
        $this->ensureCheckConstraint(
            'registros_combustible',
            'fuel_operation_pair_check',
            '(client_operation_id IS NULL AND client_operation_hash IS NULL) OR (client_operation_id IS NOT NULL AND client_operation_hash IS NOT NULL AND usuario_id IS NOT NULL)',
        );
        $this->ensureCheckConstraint(
            'orden_trabajos',
            'ot_operation_pair_check',
            '(client_operation_id IS NULL AND client_operation_hash IS NULL) OR (client_operation_id IS NOT NULL AND client_operation_hash IS NOT NULL AND created_by_user_id IS NOT NULL)',
        );
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: inventory idempotency evidence must be retained. Restore a verified backup for rollback.',
        );
    }

    /** @param  array<int, string>  $columns */
    private function ensureIndex(string $tableName, string $indexName, array $columns, bool $unique, Closure $create): void
    {
        $existing = collect(Schema::getIndexes($tableName))
            ->first(fn (array $index): bool => strtolower($index['name']) === strtolower($indexName));

        if ($existing !== null) {
            if ($existing['columns'] !== $columns || (bool) $existing['unique'] !== $unique) {
                throw new RuntimeException("Existing index {$indexName} has an incompatible shape.");
            }

            return;
        }

        Schema::table($tableName, $create);
    }

    private function ensureRestrictForeignKey(string $tableName, string $column, string $foreignTable, string $foreignColumn, string $constraintName): void
    {
        $foreignKeys = collect(Schema::getForeignKeys($tableName))
            ->filter(fn (array $key): bool => $key['columns'] === [$column])
            ->values();

        if ($foreignKeys->count() > 1) {
            throw new RuntimeException("Column {$tableName}.{$column} has multiple foreign keys.");
        }

        $existing = $foreignKeys->first();
        if ($existing !== null) {
            $deleteRule = strtoupper((string) ($existing['on_delete'] ?? ''));
            if ($existing['foreign_table'] !== $foreignTable
                || $existing['foreign_columns'] !== [$foreignColumn]
                || ! in_array($deleteRule, ['RESTRICT', 'NO ACTION'], true)) {
                throw new RuntimeException("Existing foreign key on {$tableName}.{$column} is incompatible.");
            }

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $foreignTable, $foreignColumn, $constraintName): void {
            $table->foreign($column, $constraintName)
                ->references($foreignColumn)
                ->on($foreignTable)
                ->restrictOnDelete();
        });
    }

    private function ensureCheckConstraint(string $tableName, string $constraintName, string $expression): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $existing = DB::selectOne(
            <<<'SQL'
                SELECT tc.CONSTRAINT_TYPE, tc.ENFORCED, cc.CHECK_CLAUSE
                FROM information_schema.TABLE_CONSTRAINTS tc
                LEFT JOIN information_schema.CHECK_CONSTRAINTS cc
                  ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
                 AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
                  AND tc.TABLE_NAME = ?
                  AND tc.CONSTRAINT_NAME = ?
                SQL,
            [$tableName, $constraintName],
        );

        if ($existing !== null) {
            $actualClause = preg_replace('/[\s`]+/', '', strtolower((string) ($existing->CHECK_CLAUSE ?? '')));
            $expectedClause = preg_replace('/[\s`]+/', '', strtolower($expression));
            $acceptedClauses = [$expectedClause, "({$expectedClause})", "(({$expectedClause}))"];

            if ($existing->CONSTRAINT_TYPE !== 'CHECK'
                || $existing->ENFORCED !== 'YES'
                || ! in_array($actualClause, $acceptedClauses, true)) {
                throw new RuntimeException("Existing check constraint {$constraintName} has an incompatible or unenforced definition.");
            }

            return;
        }

        $table = '`'.str_replace('`', '``', $tableName).'`';
        $constraint = '`'.str_replace('`', '``', $constraintName).'`';

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$expression})");
    }
};
