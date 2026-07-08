<?php

namespace App\Services\Inventory;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ComprasImportService
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function parseFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['csv', 'txt'], true)) {
            return $this->parseCsv($file);
        }

        return $this->parseSpreadsheet($file);
    }

    protected function parseCsv(UploadedFile $file): array
    {
        $rows = [];
        if (($handle = fopen($file->getPathname(), 'r')) === false) {
            return $rows;
        }

        $header = fgetcsv($handle, 0, ',') ?: [];
        $map = $this->buildHeaderMap($header);

        $line = 1;
        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $line++;
            $rows[] = $this->rowFromArray($data, $map, $line);
        }

        fclose($handle);

        return $rows;
    }

    protected function parseSpreadsheet(UploadedFile $file): array
    {
        $rows = [];
        $spreadsheet = IOFactory::load($file->getPathname());
        $sheet = $spreadsheet->getActiveSheet();
        $raw = $sheet->toArray(null, true, true, true);

        if (count($raw) === 0) {
            return $rows;
        }

        // Auto-detectar la fila de headers buscando columnas conocidas
        // (archivos de Siigo incluyen filas decorativas antes de los headers reales)
        $headerIndex = null;
        $map = [];
        foreach ($raw as $idx => $row) {
            $header = array_values($row);
            $candidate = $this->buildHeaderMap($header);
            if ($candidate['codigo'] !== null && $candidate['nombre'] !== null) {
                $headerIndex = $idx;
                $map = $candidate;
                break;
            }
        }

        if ($headerIndex === null) {
            return $rows;
        }

        $line = $headerIndex;
        foreach ($raw as $idx => $row) {
            if ($idx <= $headerIndex) {
                continue;
            }
            $line++;
            $data = array_values($row);
            $rows[] = $this->rowFromArray($data, $map, $line);
        }

        return $rows;
    }

    /**
     * @param array<int,string|null> $header
     * @return array<string,int|null>
     */
    protected function buildHeaderMap(array $header): array
    {
        $normalized = array_map(function ($value) {
            $v = mb_strtolower(trim((string) $value));
            $replacements = [
                'á' => 'a',
                'é' => 'e',
                'í' => 'i',
                'ó' => 'o',
                'ú' => 'u',
                'ü' => 'u',
                'ñ' => 'n',
            ];
            $v = strtr($v, $replacements);
            $v = preg_replace('/\s+/', ' ', $v ?? '') ?? '';
            return $v;
        }, $header);

        $findIndex = function (array $candidates) use ($normalized): ?int {
            foreach ($normalized as $idx => $name) {
                foreach ($candidates as $candidate) {
                    if ($name === $candidate || str_contains($name, $candidate)) {
                        return $idx;
                    }
                }
            }
            return null;
        };

        return [
            'codigo' => $findIndex(['codigo']),
            'nombre' => $findIndex(['nombre']),
            'referencia' => $findIndex(['referencia fabrica', 'referencia_fabrica', 'referencia']),
            'categoria' => $findIndex(['categoria']),
            'cantidad' => $findIndex(['saldo cantidades', 'saldo cantidad', 'saldo', 'cantidad']),
        ];
    }

    /**
     * @param array<int,string|null> $data
     */
    protected function rowFromArray(array $data, array $map, int $line): array
    {
        $get = function (?int $index) use ($data): ?string {
            if ($index === null) {
                return null;
            }
            return isset($data[$index]) ? trim((string) $data[$index]) : null;
        };

        $cantidadRaw = $get($map['cantidad'] ?? null);
        // Siigo exporta cantidades con coma como separador de miles (ej: 1,532.00)
        $cantidadClean = $cantidadRaw !== null ? str_replace(',', '', $cantidadRaw) : null;
        $cantidad = is_numeric($cantidadClean) ? (float) $cantidadClean : 0.0;

        return [
            'line' => $line,
            'sku' => $get($map['codigo'] ?? null) ?? '',
            'nombre' => $get($map['nombre'] ?? null) ?? '',
            'referencia' => $get($map['referencia'] ?? null),
            'categoria_texto' => $get($map['categoria'] ?? null),
            'cantidad' => $cantidad,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function buildPreview(array $rows, array $options): array
    {
        $existing = [];
        $new = [];
        $errors = [];
        $bySku = [];

        foreach ($rows as $row) {
            $sku = $row['sku'];
            $nombre = $row['nombre'];
            $cantidad = (float) $row['cantidad'];

            if ($sku === '' || $nombre === '') {
                $errors[] = "Fila {$row['line']}: código o nombre vacíos.";
                continue;
            }
            if ($cantidad <= 0) {
                $errors[] = "Fila {$row['line']}: cantidad no válida ({$cantidad}).";
                continue;
            }

            if (!isset($bySku[$sku])) {
                $bySku[$sku] = $row;
            } else {
                $bySku[$sku]['cantidad'] += $cantidad;
            }
        }

        $totalQtyExisting = 0.0;
        $totalQtyNew = 0.0;

        foreach ($bySku as $sku => $row) {
            $cantidad = (float) $row['cantidad'];
            $producto = Producto::where('producto_sku', $sku)->first();

            if ($producto) {
                $existing[$sku] = [
                    'producto_id' => $producto->producto_id,
                    'sku' => $sku,
                    'nombre' => $producto->producto_nombre,
                    'cantidad_total' => $cantidad,
                    'stock_actual' => (float) $producto->producto_stock_actual,
                    'categoria_bd' => $producto->categoria?->categoria_nombre,
                ];
                $totalQtyExisting += $cantidad;
                continue;
            }

            $categoriaId = $options['categoria_id'] ?? null;
            $categoriaTexto = $row['categoria_texto'] ?? null;

            if ($categoriaId === null && $categoriaTexto) {
                $categoria = Categoria::firstOrCreate(
                    ['categoria_nombre' => $categoriaTexto],
                    ['categoria_descripcion' => 'Generada automáticamente desde importación Siigo']
                );
                $categoriaId = $categoria->categoria_id;
            }

            if ($categoriaId === null) {
                $errors[] = "SKU {$sku}: no se pudo determinar categoría (columna vacía y sin categoria_id por defecto).";
                continue;
            }

            $new[$sku] = [
                'sku' => $sku,
                'nombre' => $row['nombre'],
                'referencia' => $row['referencia'],
                'cantidad_total' => $cantidad,
                'categoria_id' => $categoriaId,
                'categoria_texto' => $categoriaTexto,
            ];
            $totalQtyNew += $cantidad;
        }

        $summary = [
            'total_rows' => count($rows),
            'valid_skus' => count($bySku),
            'existing_products_count' => count($existing),
            'new_products_count' => count($new),
            'total_qty_existing' => $totalQtyExisting,
            'total_qty_new' => $totalQtyNew,
        ];

        return [
            'existing' => $existing,
            'new' => $new,
            'errors' => $errors,
            'summary' => $summary,
        ];
    }

    public function applyImport(array $preview, array $options, ?int $userId): array
    {
        $createdProducts = 0;
        $updatedProducts = 0;
        $movementsCreated = 0;
        $errors = $preview['errors'] ?? [];

        $unidad = $options['unidad_medida'] ?? 'unidad';
        $alerta = isset($options['alerta_stock_minimo']) ? (float) $options['alerta_stock_minimo'] : 5.0;
        $now = now();

        try {
            \DB::transaction(function () use (
                $preview, $userId, $unidad, $alerta, $now,
                &$createdProducts, &$updatedProducts, &$movementsCreated, &$errors
            ) {
                // --- 1) Bulk insert productos nuevos ---
                $newProductRows = [];
                $newSkuQuantities = []; // sku => cantidad_total

                foreach ($preview['new'] as $sku => $row) {
                    $newProductRows[] = [
                        'categoria_id' => $row['categoria_id'],
                        'producto_sku' => $sku,
                        'referencia_fabrica' => $row['referencia'] ?: null,
                        'producto_nombre' => $row['nombre'],
                        'producto_unidad_medida' => $unidad,
                        'producto_stock_actual' => 0,
                        'producto_alerta_stock_minimo' => $alerta,
                        'producto_precio_costo' => 0,
                        'producto_ubicacion' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $newSkuQuantities[$sku] = (float) $row['cantidad_total'];
                }

                // Insertar en chunks de 500
                foreach (array_chunk($newProductRows, 500) as $chunk) {
                    \DB::table('productos')->insert($chunk);
                }
                $createdProducts = count($newProductRows);

                // Obtener IDs de productos recién creados por SKU
                $movementRows = [];
                $quantitiesToAdd = [];

                if (!empty($newSkuQuantities)) {
                    $newProducts = Producto::whereIn('producto_sku', array_keys($newSkuQuantities))
                        ->pluck('producto_id', 'producto_sku');

                    foreach ($newProducts as $sku => $productoId) {
                        $qty = (float) $newSkuQuantities[$sku];
                        $quantitiesToAdd[$productoId] = ($quantitiesToAdd[$productoId] ?? 0.0) + $qty;
                        $movementRows[] = [
                            'producto_id' => $productoId,
                            'usuario_id' => $userId,
                            'transaccion_tipo' => 'ingreso',
                            'transaccion_cantidad' => $qty,
                            'transaccion_motivo' => 'Importación compras Siigo',
                            'transaccion_referencia_type' => 'compra',
                            'transaccion_referencia_id' => null,
                            'transaccion_notas' => 'Carga masiva desde archivo Siigo',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                // --- 2) Transacciones para productos existentes ---
                foreach ($preview['existing'] as $sku => $row) {
                    $productoId = $row['producto_id'];
                    $qty = (float) $row['cantidad_total'];
                    $quantitiesToAdd[$productoId] = ($quantitiesToAdd[$productoId] ?? 0.0) + $qty;
                    $movementRows[] = [
                        'producto_id' => $productoId,
                        'usuario_id' => $userId,
                        'transaccion_tipo' => 'ingreso',
                        'transaccion_cantidad' => $qty,
                        'transaccion_motivo' => 'Importación compras Siigo',
                        'transaccion_referencia_type' => 'compra',
                        'transaccion_referencia_id' => null,
                        'transaccion_notas' => 'Carga masiva desde archivo Siigo',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $updatedProducts++;
                }

                // Bulk insert transacciones en chunks de 500
                foreach (array_chunk($movementRows, 500) as $chunk) {
                    \DB::table('transaccion_inventarios')->insert($chunk);
                }
                $movementsCreated = count($movementRows);

                // --- 3) Incrementar stock_actual de productos afectados ---
                if (!empty($quantitiesToAdd)) {
                    ksort($quantitiesToAdd);
                    $cases = [];
                    $ids = [];
                    foreach ($quantitiesToAdd as $prodId => $qty) {
                        $cases[] = "WHEN " . (int)$prodId . " THEN " . (float)$qty;
                        $ids[] = (int)$prodId;
                    }
                    $idsString = implode(',', $ids);
                    $casesString = implode(' ', $cases);
                    \DB::statement("
                        UPDATE productos 
                        SET producto_stock_actual = producto_stock_actual + CASE producto_id
                            $casesString
                            ELSE 0
                        END
                        WHERE producto_id IN ($idsString)
                    ");
                }
            });
        } catch (\Throwable $e) {
            $errors[] = "Error en importación masiva: {$e->getMessage()}";
        }

        return [
            'message' => 'Importacion aplicada.',
            'created_products' => $createdProducts,
            'updated_products' => $updatedProducts,
            'movements_created' => $movementsCreated,
            'errors' => $errors,
        ];
    }
}

