<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ImportInventoryExcel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:import-excel {filepath}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Comando retirado: use la importación transaccional de compras desde la API';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $filePath = $this->argument('filepath');

        if (! file_exists($filePath)) {
            $this->error("El archivo no existe: {$filePath}");

            return self::FAILURE;
        }

        $this->error('Este comando fue retirado porque eliminaba productos y omitía el ledger de inventario.');
        $this->line('Use POST /api/inventario/import-compras con previsualización, bodega e idempotencia.');

        return self::FAILURE;
    }
}
