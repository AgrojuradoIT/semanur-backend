<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureRestrictForeignKey('productos', 'categoria_id', 'categorias', 'categoria_id', 'productos_categoria_restrict_fk');
        $this->ensureRestrictForeignKey('transaccion_inventarios', 'producto_id', 'productos', 'producto_id', 'trans_inv_producto_restrict_fk');
        $this->ensureRestrictForeignKey('transaccion_inventarios', 'bodega_id', 'bodegas', 'bodega_id', 'trans_inv_bodega_restrict_fk');
        $this->ensureRestrictForeignKey('prestamos_herramientas', 'producto_id', 'productos', 'producto_id', 'prestamos_producto_restrict_fk');
        $this->ensureRestrictForeignKey('bodega_producto', 'producto_id', 'productos', 'producto_id', 'bodega_producto_producto_restrict_fk');
        $this->ensureRestrictForeignKey('bodega_producto', 'bodega_id', 'bodegas', 'bodega_id', 'bodega_producto_bodega_restrict_fk');

        if (! Schema::hasTable('inventario_importaciones')) {
            Schema::create('inventario_importaciones', function (Blueprint $table): void {
                $table->id();
                $table->string('idempotency_key', 64)->unique();
                $table->string('request_hash', 64);
                $table->string('file_sha256', 64);
                $table->foreignId('bodega_id')
                    ->constrained('bodegas', 'bodega_id')
                    ->restrictOnDelete();
                $table->foreignId('usuario_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->string('estado', 20);
                $table->json('resultado')->nullable();
                $table->timestamps();
            });

            return;
        }

        $requiredColumns = [
            'id', 'idempotency_key', 'request_hash', 'file_sha256', 'bodega_id',
            'usuario_id', 'estado', 'resultado', 'created_at', 'updated_at',
        ];
        foreach ($requiredColumns as $column) {
            if (! Schema::hasColumn('inventario_importaciones', $column)) {
                throw new RuntimeException(
                    "Existing inventario_importaciones table is incomplete; missing {$column}.",
                );
            }
        }

        $idempotencyIndex = collect(Schema::getIndexes('inventario_importaciones'))
            ->first(fn (array $index): bool => strtolower($index['name']) === 'inventario_importaciones_idempotency_key_unique');
        if ($idempotencyIndex === null
            || $idempotencyIndex['columns'] !== ['idempotency_key']
            || ! $idempotencyIndex['unique']) {
            throw new RuntimeException('Existing inventory import idempotency index is incompatible.');
        }

        $this->assertForeignKey('inventario_importaciones', 'bodega_id', 'bodegas', 'bodega_id', ['RESTRICT', 'NO ACTION']);
        $this->assertForeignKey('inventario_importaciones', 'usuario_id', 'users', 'id', ['SET NULL']);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: inventory containment and import evidence must be retained. Restore a verified backup for rollback.',
        );
    }

    private function ensureRestrictForeignKey(
        string $tableName,
        string $column,
        string $foreignTable,
        string $foreignColumn,
        string $constraintName,
    ): void {
        $allForeignKeys = collect(Schema::getForeignKeys($tableName));
        $foreignKeys = $allForeignKeys
            ->filter(fn (array $key): bool => $key['columns'] === [$column])
            ->values();

        if ($foreignKeys->count() > 1) {
            throw new RuntimeException("Column {$tableName}.{$column} has multiple foreign keys.");
        }

        $existing = $foreignKeys->first();
        if ($existing === null) {
            Schema::table($tableName, function (Blueprint $table) use ($column, $foreignTable, $foreignColumn, $constraintName): void {
                $table->foreign($column, $constraintName)
                    ->references($foreignColumn)
                    ->on($foreignTable)
                    ->restrictOnDelete();
            });

            return;
        }

        if ($existing['foreign_table'] !== $foreignTable || $existing['foreign_columns'] !== [$foreignColumn]) {
            throw new RuntimeException("Existing foreign key on {$tableName}.{$column} targets an unexpected key.");
        }

        if (in_array(strtoupper((string) $existing['on_delete']), ['RESTRICT', 'NO ACTION'], true)) {
            return;
        }

        if ($allForeignKeys->contains(fn (array $key): bool => ($key['name'] ?? null) === $constraintName
            && ($key['name'] ?? null) !== ($existing['name'] ?? null))) {
            throw new RuntimeException("Constraint name {$constraintName} is already in use.");
        }

        if (DB::getDriverName() === 'mysql') {
            $table = $this->quoteIdentifier($tableName);
            $oldConstraint = $this->quoteIdentifier((string) $existing['name']);
            $newConstraint = $this->quoteIdentifier($constraintName);
            $localColumn = $this->quoteIdentifier($column);
            $referencedTable = $this->quoteIdentifier($foreignTable);
            $referencedColumn = $this->quoteIdentifier($foreignColumn);

            DB::statement(
                "ALTER TABLE {$table} DROP FOREIGN KEY {$oldConstraint}, "
                ."ADD CONSTRAINT {$newConstraint} FOREIGN KEY ({$localColumn}) "
                ."REFERENCES {$referencedTable} ({$referencedColumn}) ON DELETE RESTRICT",
            );

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $foreignTable, $foreignColumn, $constraintName): void {
            $table->dropForeign([$column]);
            $table->foreign($column, $constraintName)
                ->references($foreignColumn)
                ->on($foreignTable)
                ->restrictOnDelete();
        });
    }

    /** @param  array<int, string>  $allowedDeleteRules */
    private function assertForeignKey(
        string $tableName,
        string $column,
        string $foreignTable,
        string $foreignColumn,
        array $allowedDeleteRules,
    ): void {
        $matches = collect(Schema::getForeignKeys($tableName))
            ->filter(fn (array $key): bool => $key['columns'] === [$column]
                && $key['foreign_table'] === $foreignTable
                && $key['foreign_columns'] === [$foreignColumn]
                && in_array(strtoupper((string) $key['on_delete']), $allowedDeleteRules, true));

        if ($matches->count() !== 1) {
            throw new RuntimeException("Existing foreign key on {$tableName}.{$column} is incompatible.");
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
};
