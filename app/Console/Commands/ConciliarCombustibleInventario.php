<?php

namespace App\Console\Commands;

use App\Models\Producto;
use App\Models\RegistroCombustible;
use App\Models\User;
use App\Services\CombustibleService;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ConciliarCombustibleInventario extends Command
{
    protected $signature = 'combustible:conciliar-inventario
                            {--registro=* : Limitar la auditoría o conciliación a registros específicos}
                            {--apply : Crear contraasientos y nuevas salidas para las inconsistencias detectadas}
                            {--usuario= : ID del usuario responsable de la conciliación}
                            {--force : Omitir la confirmación interactiva al aplicar}';

    protected $description = 'Audita y, opcionalmente, concilia registros de combustible contra su salida de inventario enlazada';

    public function __construct(
        private readonly CombustibleService $combustibleService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $fuelProducts = $this->resolveFuelProducts();

        if ($fuelProducts === null) {
            return self::FAILURE;
        }

        $recordOptions = collect($this->option('registro'));
        $invalidRecordOptions = $recordOptions->filter(
            static fn (mixed $id): bool => filter_var(
                $id,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            ) === false
        );

        if ($invalidRecordOptions->isNotEmpty()) {
            $this->error('Cada valor de --registro debe ser un ID entero positivo. No se ejecutó la auditoría.');

            return self::FAILURE;
        }

        $recordIds = $recordOptions
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $mismatches = $this->mismatchQuery($fuelProducts, $recordIds)->get();

        if ($mismatches->isEmpty()) {
            $this->info('No se encontraron inconsistencias entre tanqueos y salidas de inventario.');

            return self::SUCCESS;
        }

        $unsafeProjection = $this->renderAudit($mismatches, $fuelProducts);

        if (! $this->option('apply')) {
            $this->warn('Modo auditoría: no se modificó ningún dato. Use --apply con --usuario para conciliar.');

            return self::SUCCESS;
        }

        $user = $this->resolveAuditUser();

        if (! $user) {
            return self::FAILURE;
        }

        if ($unsafeProjection) {
            $this->error('La conciliación fue bloqueada porque produciría un saldo negativo global o por bodega.');

            return self::FAILURE;
        }

        $uncorrectable = $mismatches->filter(
            static fn (object $row): bool => $row->ledger_transaction_id === null
                || strtolower((string) $row->ledger_type) !== 'salida'
                || $row->bodega_id === null
                || (int) $row->linked_record_count !== 1
                || $row->existing_reversal_id !== null
        );

        if ($uncorrectable->isNotEmpty()) {
            $this->error('La conciliación fue cancelada: hay salidas ausentes, compartidas, ya revertidas o sin bodega.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            "Se crearán contraasientos y nuevas salidas para {$mismatches->count()} registro(s). ¿Continuar?",
            false,
        )) {
            $this->info('Conciliación cancelada.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($mismatches, $user): void {
                foreach ($mismatches as $mismatch) {
                    $registro = RegistroCombustible::query()->findOrFail($mismatch->registro_id);
                    $this->combustibleService->updateRegistro($registro, [], $user->id);
                }
            });
        } catch (Throwable $exception) {
            $this->error('No se aplicó ninguna conciliación. Toda la operación fue revertida.');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Conciliación completada: {$mismatches->count()} registro(s) corregido(s) con trazabilidad en el ledger.");

        return self::SUCCESS;
    }

    /**
     * @return array{gasolina: Producto, acpm: Producto}|null
     */
    private function resolveFuelProducts(): ?array
    {
        $gasolina = $this->combustibleService->resolveCombustibleProducto('gasolina');
        $acpm = $this->combustibleService->resolveCombustibleProducto('acpm');

        if (! $gasolina || ! $acpm) {
            $this->error('No se pudieron resolver los productos exactos de GASOLINA y ACPM.');

            return null;
        }

        return compact('gasolina', 'acpm');
    }

    /**
     * @param  array{gasolina: Producto, acpm: Producto}  $fuelProducts
     * @param  array<int, int>  $recordIds
     */
    private function mismatchQuery(array $fuelProducts, array $recordIds): Builder
    {
        $gasolineId = (int) $fuelProducts['gasolina']->producto_id;
        $acpmId = (int) $fuelProducts['acpm']->producto_id;
        $hasReversalCol = Schema::hasColumn('transaccion_inventarios', 'reverses_transaction_id');

        $query = DB::table('registros_combustible as rc')
            ->leftJoin('transaccion_inventarios as ti', 'ti.transaccion_id', '=', 'rc.transaccion_id')
            ->leftJoin('productos as p', 'p.producto_id', '=', 'ti.producto_id');

        if ($hasReversalCol) {
            $query->leftJoin('transaccion_inventarios as reversal', 'reversal.reverses_transaction_id', '=', 'ti.transaccion_id');
        }

        $query->select([
            'rc.registro_id',
            'rc.fecha',
            'rc.tipo_combustible as current_fuel_type',
            'rc.cantidad_galones as current_quantity',
            'rc.transaccion_id',
            'ti.transaccion_id as ledger_transaction_id',
            'ti.producto_id as ledger_product_id',
            'p.producto_nombre as ledger_product_name',
            'ti.transaccion_tipo as ledger_type',
            'ti.transaccion_cantidad as ledger_quantity',
            'ti.bodega_id',
            $hasReversalCol
                ? 'reversal.transaccion_id as existing_reversal_id'
                : DB::raw('NULL as existing_reversal_id'),
            DB::raw('(SELECT COUNT(*) FROM registros_combustible rc2 WHERE rc2.transaccion_id = ti.transaccion_id) as linked_record_count'),
            'rc.updated_at',
        ])
        ->where(function (Builder $query) use ($gasolineId, $acpmId, $hasReversalCol): void {
            $query
                ->whereNull('ti.transaccion_id')
                ->orWhere('ti.transaccion_tipo', '!=', 'salida')
                ->orWhereNull('ti.bodega_id');

            if ($hasReversalCol) {
                $query->orWhereNotNull('reversal.transaccion_id');
            }

            $query
                ->orWhereRaw('(SELECT COUNT(*) FROM registros_combustible rc3 WHERE rc3.transaccion_id = ti.transaccion_id) <> 1')
                ->orWhereRaw('LOWER(rc.tipo_combustible) NOT IN (?, ?)', ['gasolina', 'acpm'])
                ->orWhereRaw(
                    'CASE WHEN LOWER(rc.tipo_combustible) = ? THEN ? WHEN LOWER(rc.tipo_combustible) = ? THEN ? ELSE NULL END <> ti.producto_id',
                    ['gasolina', $gasolineId, 'acpm', $acpmId],
                )
                ->orWhereRaw('ABS(rc.cantidad_galones - ti.transaccion_cantidad) > 0.004');
        })
        ->orderBy('rc.registro_id');

        if ($recordIds !== []) {
            $query->whereIn('rc.registro_id', $recordIds);
        }

        return $query;
    }

    /**
     * @param  Collection<int, object>  $mismatches
     * @param  array{gasolina: Producto, acpm: Producto}  $fuelProducts
     */
    private function renderAudit(Collection $mismatches, array $fuelProducts): bool
    {
        $this->warn("Se detectaron {$mismatches->count()} inconsistencia(s):");
        $this->table(
            ['Registro', 'Fecha', 'Tanqueo actual', 'Salida enlazada', 'Actualizado'],
            $mismatches->map(static function (object $row): array {
                $ledger = $row->ledger_transaction_id === null
                    ? 'SIN TRANSACCIÓN'
                    : sprintf(
                        '%s %.2f gal [%s #%d]',
                        $row->ledger_product_name ?? 'Producto desconocido',
                        (float) $row->ledger_quantity,
                        $row->ledger_type,
                        $row->ledger_transaction_id,
                    );

                return [
                    $row->registro_id,
                    $row->fecha,
                    sprintf('%s %.2f gal', strtoupper((string) $row->current_fuel_type), (float) $row->current_quantity),
                    $ledger,
                    $row->updated_at,
                ];
            })->all(),
        );

        $deltas = $this->projectedDeltas($mismatches, $fuelProducts);
        $this->table(
            ['Producto', 'Stock actual', 'Delta proyectado', 'Stock posterior'],
            collect($fuelProducts)->map(static function (Producto $product, string $type) use ($deltas): array {
                $stock = (float) $product->producto_stock_actual;
                $delta = $deltas[(int) $product->producto_id] ?? 0.0;

                return [
                    strtoupper($type),
                    number_format($stock, 2, '.', ''),
                    sprintf('%+.2f', $delta),
                    number_format($stock + $delta, 2, '.', ''),
                ];
            })->values()->all(),
        );

        $unsafeGlobalProjection = collect($fuelProducts)->contains(function (Producto $product) use ($deltas): bool {
            return (float) $product->producto_stock_actual + ($deltas[(int) $product->producto_id] ?? 0.0) < 0;
        });
        $unsafeWarehouseProjection = $this->renderWarehouseProjection($mismatches, $fuelProducts);

        if ($unsafeGlobalProjection || $unsafeWarehouseProjection) {
            $this->error('ALERTA: la conciliación proyecta stock negativo global o por bodega. Verifique el conteo físico antes de aplicar.');
        }

        return $unsafeGlobalProjection || $unsafeWarehouseProjection;
    }

    /**
     * @param  Collection<int, object>  $mismatches
     * @param  array{gasolina: Producto, acpm: Producto}  $fuelProducts
     * @return array<int, float>
     */
    private function projectedDeltas(Collection $mismatches, array $fuelProducts): array
    {
        $expectedProductIds = [
            'gasolina' => (int) $fuelProducts['gasolina']->producto_id,
            'acpm' => (int) $fuelProducts['acpm']->producto_id,
        ];
        $deltas = [];

        foreach ($mismatches as $row) {
            if ($row->ledger_product_id !== null && $row->ledger_quantity !== null) {
                $ledgerProductId = (int) $row->ledger_product_id;
                $deltas[$ledgerProductId] = ($deltas[$ledgerProductId] ?? 0.0) + (float) $row->ledger_quantity;
            }

            $expectedProductId = $expectedProductIds[strtolower((string) $row->current_fuel_type)] ?? null;

            if ($expectedProductId !== null) {
                $deltas[$expectedProductId] = ($deltas[$expectedProductId] ?? 0.0) - (float) $row->current_quantity;
            }
        }

        return $deltas;
    }

    /**
     * @param  Collection<int, object>  $mismatches
     * @param  array{gasolina: Producto, acpm: Producto}  $fuelProducts
     */
    private function renderWarehouseProjection(Collection $mismatches, array $fuelProducts): bool
    {
        $expectedProductIds = [
            'gasolina' => (int) $fuelProducts['gasolina']->producto_id,
            'acpm' => (int) $fuelProducts['acpm']->producto_id,
        ];
        $productNames = collect($fuelProducts)->keyBy('producto_id');
        $deltas = [];

        foreach ($mismatches as $row) {
            if ($row->bodega_id === null) {
                continue;
            }

            $bodegaId = (int) $row->bodega_id;

            if ($row->ledger_product_id !== null && $row->ledger_quantity !== null) {
                $ledgerProductId = (int) $row->ledger_product_id;
                $key = "{$bodegaId}:{$ledgerProductId}";
                $deltas[$key] = ($deltas[$key] ?? 0.0) + (float) $row->ledger_quantity;
            }

            $expectedProductId = $expectedProductIds[strtolower((string) $row->current_fuel_type)] ?? null;

            if ($expectedProductId !== null) {
                $key = "{$bodegaId}:{$expectedProductId}";
                $deltas[$key] = ($deltas[$key] ?? 0.0) - (float) $row->current_quantity;
            }
        }

        if ($deltas === []) {
            return $mismatches->contains(static fn (object $row): bool => $row->bodega_id === null);
        }

        $bodegaIds = collect(array_keys($deltas))
            ->map(static fn (string $key): int => (int) explode(':', $key, 2)[0])
            ->unique()
            ->values();
        $productIds = collect(array_keys($deltas))
            ->map(static fn (string $key): int => (int) explode(':', $key, 2)[1])
            ->unique()
            ->values();
        $bodegaNames = DB::table('bodegas')
            ->whereIn('bodega_id', $bodegaIds)
            ->pluck('nombre', 'bodega_id');
        $stocks = DB::table('bodega_producto')
            ->whereIn('bodega_id', $bodegaIds)
            ->whereIn('producto_id', $productIds)
            ->get()
            ->keyBy(static fn (object $row): string => "{$row->bodega_id}:{$row->producto_id}");
        $unsafe = false;

        $rows = collect($deltas)->map(function (float $delta, string $key) use (
            $bodegaNames,
            $productNames,
            $stocks,
            &$unsafe,
        ): array {
            [$bodegaId, $productId] = array_map('intval', explode(':', $key, 2));
            $stock = (float) ($stocks->get($key)?->cantidad ?? 0.0);
            $projected = $stock + $delta;
            $unsafe = $unsafe || $projected < -0.004;

            return [
                "#{$bodegaId} ".($bodegaNames[$bodegaId] ?? 'Bodega desconocida'),
                $productNames->get($productId)?->producto_nombre ?? "Producto #{$productId}",
                number_format($stock, 2, '.', ''),
                sprintf('%+.2f', $delta),
                number_format($projected, 2, '.', ''),
            ];
        })->values()->all();

        $this->table(
            ['Bodega', 'Producto', 'Stock actual', 'Delta proyectado', 'Stock posterior'],
            $rows,
        );

        return $unsafe;
    }

    private function resolveAuditUser(): ?User
    {
        $userId = $this->option('usuario');

        if (! is_numeric($userId) || (int) $userId <= 0) {
            $this->error('Debe indicar --usuario=<ID> para atribuir la conciliación.');

            return null;
        }

        $user = User::query()->find((int) $userId);

        if (! $user) {
            $this->error("No existe el usuario #{$userId}.");

            return null;
        }

        return $user;
    }
}
