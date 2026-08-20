<?php

namespace App\Console\Commands;

use App\Models\Producto;
use App\Services\CombustibleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditarCombustibleLedgerPorIngreso extends Command
{
    protected $signature = 'combustible:auditar-ledger-por-ingreso
                            {--producto=* : Filtrar por gasolina o acpm}';

    protected $description = 'Reconstruye ingresos, salidas y saldo acumulado del combustible por fecha de ingreso';

    public function __construct(
        private readonly CombustibleService $combustibleService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $requestedTypes = collect($this->option('producto'))
            ->map(static fn (mixed $value): string => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values();

        $invalidTypes = $requestedTypes->diff(['gasolina', 'acpm']);

        if ($invalidTypes->isNotEmpty()) {
            $this->error('Los valores de --producto deben ser gasolina o acpm.');

            return self::FAILURE;
        }

        $types = $requestedTypes->isEmpty()
            ? collect(['gasolina', 'acpm'])
            : $requestedTypes;

        foreach ($types as $type) {
            $product = $this->combustibleService->resolveCombustibleProducto($type);

            if (! $product) {
                $this->error("No se encontró el producto de combustible: {$type}.");

                return self::FAILURE;
            }

            $this->auditProduct($type, $product);
        }

        $this->info('Auditoría completada en modo lectura: no se modificó ningún dato.');

        return self::SUCCESS;
    }

    private function auditProduct(string $type, Producto $product): void
    {
        $rows = DB::table('transaccion_inventarios')
            ->where('producto_id', $product->producto_id)
            ->whereIn('transaccion_tipo', ['ingreso', 'salida'])
            ->orderBy('created_at')
            ->orderBy('transaccion_id')
            ->get([
                'transaccion_id',
                'bodega_id',
                'transaccion_tipo',
                'transaccion_cantidad',
                'transaccion_motivo',
                'created_at',
            ]);

        $this->newLine();
        $this->line('<options=bold>'.strtoupper($type).' (producto #'.$product->producto_id.')</>');

        if ($rows->isEmpty()) {
            $this->warn('No hay movimientos de ingreso o salida para este producto.');

            return;
        }

        $totalIncome = $rows
            ->filter(static fn (object $row): bool => strtolower((string) $row->transaccion_tipo) === 'ingreso')
            ->sum(static fn (object $row): float => (float) $row->transaccion_cantidad);
        $totalOutput = $rows
            ->filter(static fn (object $row): bool => strtolower((string) $row->transaccion_tipo) === 'salida')
            ->sum(static fn (object $row): float => (float) $row->transaccion_cantidad);
        $storedStock = (float) $product->producto_stock_actual;
        $openingBalance = $storedStock - $totalIncome + $totalOutput;
        $runningBalance = $openingBalance;
        $outputsBeforeFirstIncome = 0.0;
        $periodOutputs = 0.0;
        $lastIncome = null;
        $periods = collect();

        foreach ($rows as $row) {
            $quantity = (float) $row->transaccion_cantidad;
            $isIncome = strtolower((string) $row->transaccion_tipo) === 'ingreso';

            if ($isIncome) {
                if ($lastIncome !== null) {
                    $periods->push($this->periodRow(
                        $lastIncome,
                        $periodOutputs,
                        $runningBalance,
                        'hasta el siguiente ingreso',
                    ));
                }

                $runningBalance += $quantity;
                $lastIncome = [
                    'id' => (int) $row->transaccion_id,
                    'fecha' => (string) $row->created_at,
                    'cantidad' => $quantity,
                    'motivo' => trim((string) ($row->transaccion_motivo ?? '')) ?: 'Sin motivo',
                ];
                $periodOutputs = 0.0;

                continue;
            }

            $runningBalance -= $quantity;

            if ($lastIncome === null) {
                $outputsBeforeFirstIncome += $quantity;
            } else {
                $periodOutputs += $quantity;
            }
        }

        if ($lastIncome !== null) {
            $periods->push($this->periodRow(
                $lastIncome,
                $periodOutputs,
                $runningBalance,
                'hasta el corte actual',
            ));
        }

        $this->table(
            ['Saldo inicial implícito', 'Total ingresos', 'Total salidas', 'Saldo calculado', 'Saldo registrado'],
            [[
                $this->formatQuantity($openingBalance),
                $this->formatQuantity($totalIncome),
                $this->formatQuantity($totalOutput),
                $this->formatQuantity($runningBalance),
                $this->formatQuantity($storedStock),
            ]],
        );

        $this->table(
            ['Ingreso', 'Fecha', 'Cantidad', 'Motivo', 'Salidas posteriores', 'Saldo al corte'],
            $periods->all(),
        );

        if ($outputsBeforeFirstIncome > 0.004) {
            $this->warn(
                'Salidas anteriores al primer ingreso registrado: '
                .$this->formatQuantity($outputsBeforeFirstIncome).' galones. '
                .'Esto depende del saldo inicial implícito.',
            );
        }

        if (abs($runningBalance - $storedStock) > 0.004) {
            $this->error('El saldo reconstruido no coincide con producto_stock_actual.');
        }

        $nullWarehouseIncome = $rows
            ->filter(static fn (object $row): bool => strtolower((string) $row->transaccion_tipo) === 'ingreso')
            ->filter(static fn (object $row): bool => $row->bodega_id === null)
            ->sum(static fn (object $row): float => (float) $row->transaccion_cantidad);

        if ($nullWarehouseIncome > 0.004) {
            $this->line(
                'Nota: '.$this->formatQuantity($nullWarehouseIncome)
                .' galones ingresaron al stock global sin bodega; las salidas están asociadas a bodega.',
            );
        }
    }

    /**
     * @param  array{id: int, fecha: string, cantidad: float, motivo: string}  $income
     * @return array<int, string>
     */
    private function periodRow(array $income, float $outputs, float $balance, string $cutoff): array
    {
        return [
            '#'.$income['id'],
            $income['fecha'].' ('.$cutoff.')',
            $this->formatQuantity($income['cantidad']),
            $income['motivo'],
            $this->formatQuantity($outputs),
            $this->formatQuantity($balance),
        ];
    }

    private function formatQuantity(float $quantity): string
    {
        return number_format($quantity, 2, '.', '');
    }
}
