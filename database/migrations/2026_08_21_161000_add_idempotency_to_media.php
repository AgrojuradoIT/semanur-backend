<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media')) {
            return;
        }

        Schema::table('media', function (Blueprint $table): void {
            if (! Schema::hasColumn('media', 'client_operation_id')) {
                $table->char('client_operation_id', 36)->nullable()->after('created_by');
            }
            if (! Schema::hasColumn('media', 'client_operation_hash')) {
                $table->char('client_operation_hash', 64)->nullable()->after('client_operation_id');
            }
            if (! Schema::hasColumn('media', 'content_sha256')) {
                $table->char('content_sha256', 64)->nullable()->after('client_operation_hash');
            }
        });

        if (! Schema::hasIndex('media', 'media_creator_operation_unique', 'unique')) {
            Schema::table('media', function (Blueprint $table): void {
                $table->unique(
                    ['created_by', 'client_operation_id'],
                    'media_creator_operation_unique'
                );
            });
        }

        $this->ensureCheckConstraint(
            'media',
            'media_operation_pair_check',
            '(client_operation_id IS NULL AND client_operation_hash IS NULL) OR (client_operation_id IS NOT NULL AND client_operation_hash IS NOT NULL AND created_by IS NOT NULL)',
        );
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: evidence idempotency metadata must be retained. Restore a verified backup for rollback.'
        );
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
