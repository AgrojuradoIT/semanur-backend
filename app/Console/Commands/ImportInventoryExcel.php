<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\Producto;
use App\Models\Categoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    protected $description = 'Limpia la base de datos de productos e importa desde un archivo Excel';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = $this->argument('filepath');

        if (!file_exists($filePath)) {
            $this->error("El archivo no existe: {$filePath}");
            return 1;
        }

        $this->info("Cargando archivo Excel...");

        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
        } catch (\Exception $e) {
            $this->error("Error al leer el Excel: " . $e->getMessage());
            return 1;
        }

        if (count($rows) <= 1) {
            $this->error("El archivo está vacío o solo tiene cabeceras.");
            return 1;
        }

        if (!$this->confirm('Esto vaciará TOTALMENTE la tabla actual de productos. ¿Estás seguro?', true)) {
            $this->info("Operación cancelada.");
            return 0;
        }

        // Vaciar la tabla (Deshabilitando constraints por llaves foráneas si hubiese)
        $this->info("Vaciando tabla de productos...");
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Producto::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->info("Procesando filas...");
        $insertedCount = 0;
        $errorCount = 0;

        // Skip row 0 (headers)
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            
            // Mapeo según la estructura REAL del Excel (verificada):
            // 0 -> Tipo (Producto/Servicio - ignorado)
            // 1 -> Código Interno (SKU)
            // 2 -> Nombre
            // 3 -> Referencia Fábrica
            // 4 -> Categoría
            // 5 -> Cantidad Física (Stock)

            $tipo = trim($row[0] ?? '');
            $codigo = trim($row[1] ?? '');
            $nombre = trim($row[2] ?? '');
            $referencia = trim($row[3] ?? '');
            $categoriaNom = trim($row[4] ?? '');
            $cantidadStr = trim($row[5] ?? '0');

            if (empty($codigo) || empty($nombre)) {
                $errorCount++;
                continue; // Saltar fila vacía o sin código
            }

            // Normalizar cantidad
            $cantidad = is_numeric($cantidadStr) ? floatval($cantidadStr) : 0;

            // Manejo de categoría (Crear si no existe)
            $categoriaId = null;
            if (!empty($categoriaNom)) {
                $cat = Categoria::firstOrCreate(
                    ['categoria_nombre' => $categoriaNom],
                    ['categoria_descripcion' => "Generado autómaticamente desde $categoriaNom"]
                );
                $categoriaId = $cat->categoria_id;
            }

            try {
                Producto::create([
                    'categoria_id' => $categoriaId,
                    'producto_sku' => $codigo,
                    'referencia_fabrica' => $referencia !== '' ? $referencia : null,
                    'producto_nombre' => $nombre,
                    'producto_stock_actual' => $cantidad,
                    'producto_unidad_medida' => 'unidad', // Default
                    'producto_alerta_stock_minimo' => 5, // Default
                    'producto_precio_costo' => 0,
                ]);
                $insertedCount++;
            } catch (\Exception $e) {
                $this->error("Falla al insertar $codigo: " . $e->getMessage());
                $errorCount++;
            }
        }

        $this->info("Importación finalizada. Insertados: $insertedCount. Errores/Omitidos: $errorCount.");
        return 0;
    }
}
