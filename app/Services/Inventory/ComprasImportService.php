<?php

namespace App\Services\Inventory;

use App\Exceptions\InventoryImportConflictException;
use App\Models\Bodega;
use App\Models\Categoria;
use App\Models\InventarioImportacion;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LengthException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;
use ZipArchive;

class ComprasImportService
{
    /** @return array<int,array<string,mixed>> */
    public function parseFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['csv', 'txt'], true)) {
            return $this->parseCsv($file);
        }

        if ($extension !== 'xlsx') {
            throw new RuntimeException('Sólo se permiten archivos CSV, TXT o XLSX. El formato XLS legado está deshabilitado.');
        }

        return $this->parseSpreadsheet($file);
    }

    public function requestHash(UploadedFile $file, array $options): string
    {
        $fileHash = hash_file('sha256', $file->getPathname());
        if ($fileHash === false) {
            throw new RuntimeException('No se pudo calcular la huella del archivo.');
        }

        return hash('sha256', json_encode([
            'file_sha256' => $fileHash,
            'bodega_id' => (int) $options['bodega_id'],
            'categoria_id' => isset($options['categoria_id']) ? (int) $options['categoria_id'] : null,
            'unidad_medida' => (string) ($options['unidad_medida'] ?? 'unidad'),
            'alerta_stock_minimo' => (float) ($options['alerta_stock_minimo'] ?? 5),
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    protected function parseCsv(UploadedFile $file): array
    {
        $rows = [];
        $handle = fopen($file->getPathname(), 'r');
        if ($handle === false) {
            return $rows;
        }

        try {
            $header = fgetcsv($handle, 0, ',') ?: [];
            $map = $this->buildHeaderMap($header);
            $line = 1;

            while (($data = fgetcsv($handle, 0, ',')) !== false) {
                $line++;
                $rows[] = $this->rowFromArray($data, $map, $line);
                $this->ensureRowLimit(count($rows));
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    protected function parseSpreadsheet(UploadedFile $file): array
    {
        $this->assertSafeXlsxArchive($file);

        $maxRows = max(1, (int) config('inventory.imports.max_rows', 5000));
        $headerScanRows = max(1, (int) config('inventory.imports.header_scan_rows', 100));
        $maxColumns = min(max(1, (int) config('inventory.imports.max_columns', 64)), 256);
        $lastReadRow = $maxRows + $headerScanRows + 1;
        $lastColumn = Coordinate::stringFromColumnIndex($maxColumns);

        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new class($lastReadRow, $maxColumns) implements IReadFilter
        {
            public function __construct(
                private readonly int $lastRow,
                private readonly int $lastColumn,
            ) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->lastRow
                    && Coordinate::columnIndexFromString($columnAddress) <= $this->lastColumn;
            }
        });
        $spreadsheet = $reader->load($file->getPathname());

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $raw = $sheet->rangeToArray(
                "A1:{$lastColumn}{$lastReadRow}",
                null,
                false,
                true,
                true,
            );
            if ($raw === []) {
                return [];
            }

            $headerIndex = null;
            $map = [];
            foreach ($raw as $idx => $row) {
                $candidate = $this->buildHeaderMap(array_values($row));
                if ($candidate['codigo'] !== null && $candidate['nombre'] !== null) {
                    $headerIndex = $idx;
                    $map = $candidate;
                    break;
                }
            }

            if ($headerIndex === null) {
                return [];
            }

            $rows = [];
            foreach ($raw as $idx => $row) {
                if ($idx <= $headerIndex) {
                    continue;
                }

                if (collect($row)->every(static fn (mixed $value): bool => $value === null || trim((string) $value) === '')) {
                    continue;
                }

                $rows[] = $this->rowFromArray(array_values($row), $map, (int) $idx);
                $this->ensureRowLimit(count($rows));
            }

            return $rows;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    protected function ensureRowLimit(int $rows): void
    {
        $limit = max(1, (int) config('inventory.imports.max_rows', 5000));
        if ($rows > $limit) {
            throw new LengthException("El archivo supera el límite de {$limit} filas.");
        }
    }

    protected function assertSafeXlsxArchive(UploadedFile $file): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('El servidor no tiene soporte ZIP para validar archivos XLSX.');
        }

        $archive = new ZipArchive;
        if ($archive->open($file->getPathname(), ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('El archivo XLSX no es un contenedor ZIP válido.');
        }

        try {
            $maxEntries = max(1, (int) config('inventory.imports.max_zip_entries', 256));
            $maxUncompressed = max(1, (int) config('inventory.imports.max_uncompressed_bytes', 33554432));

            if ($archive->numFiles > $maxEntries) {
                throw new RuntimeException("El XLSX supera el límite de {$maxEntries} entradas internas.");
            }

            $totalUncompressed = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index);
                if ($stat === false) {
                    throw new RuntimeException('No se pudo inspeccionar una entrada interna del XLSX.');
                }

                $totalUncompressed += (int) ($stat['size'] ?? 0);
                if ($totalUncompressed > $maxUncompressed) {
                    throw new RuntimeException('El XLSX excede el límite de contenido descomprimido.');
                }
            }

            if ($archive->locateName('[Content_Types].xml') === false
                || $archive->locateName('xl/workbook.xml') === false) {
                throw new RuntimeException('El archivo no contiene la estructura mínima de un XLSX.');
            }
        } finally {
            $archive->close();
        }
    }

    /** @param array<int,string|null> $header @return array<string,int|null> */
    protected function buildHeaderMap(array $header): array
    {
        $normalized = array_map(function ($value) {
            $value = mb_strtolower(trim((string) $value));
            $value = strtr($value, [
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o',
                'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            ]);

            return preg_replace('/\s+/', ' ', $value) ?? '';
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

    /** @param array<int,string|null> $data */
    protected function rowFromArray(array $data, array $map, int $line): array
    {
        $get = static function (?int $index) use ($data): ?string {
            if ($index === null) {
                return null;
            }

            if (! isset($data[$index])) {
                return null;
            }

            $value = trim((string) $data[$index]);
            if (preg_match('/^[=+@\t\r]/u', $value) === 1) {
                throw new RuntimeException('El archivo contiene fórmulas o valores ejecutables no permitidos.');
            }

            return $value;
        };

        $cantidadRaw = $get($map['cantidad'] ?? null);
        $cantidadClean = $cantidadRaw !== null ? str_replace(',', '', $cantidadRaw) : null;

        return [
            'line' => $line,
            'sku' => $get($map['codigo'] ?? null) ?? '',
            'nombre' => $get($map['nombre'] ?? null) ?? '',
            'referencia' => $get($map['referencia'] ?? null),
            'categoria_texto' => $get($map['categoria'] ?? null),
            'cantidad' => is_numeric($cantidadClean) ? (float) $cantidadClean : 0.0,
        ];
    }

    /**
     * Build a read-only preview using bounded bulk lookups.
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function buildPreview(array $rows, array $options): array
    {
        $errors = [];
        $bySku = [];

        foreach ($rows as $row) {
            $sku = trim((string) $row['sku']);
            $nombre = trim((string) $row['nombre']);
            $cantidad = (float) $row['cantidad'];

            if ($sku === '' || $nombre === '') {
                $errors[] = "Fila {$row['line']}: código o nombre vacíos.";

                continue;
            }
            if ($cantidad <= 0) {
                $errors[] = "Fila {$row['line']}: cantidad no válida ({$cantidad}).";

                continue;
            }

            $key = mb_strtoupper($sku);
            if (! isset($bySku[$key])) {
                $row['sku'] = $sku;
                $row['nombre'] = $nombre;
                $bySku[$key] = $row;
            } else {
                $bySku[$key]['cantidad'] += $cantidad;
            }
        }

        $products = Producto::query()
            ->with('categoria')
            ->whereIn('producto_sku', array_column($bySku, 'sku'))
            ->get()
            ->keyBy(static fn (Producto $producto): string => mb_strtoupper($producto->producto_sku));

        $categoryNames = collect($bySku)
            ->filter(static fn (array $row, string $key): bool => ! $products->has($key))
            ->pluck('categoria_texto')
            ->filter()
            ->map(static fn ($name): string => trim((string) $name))
            ->unique()
            ->values();

        $categories = Categoria::query()
            ->whereIn('categoria_nombre', $categoryNames)
            ->orderBy('categoria_id')
            ->get()
            ->keyBy(static fn (Categoria $categoria): string => mb_strtoupper($categoria->categoria_nombre));

        $existing = [];
        $new = [];
        $totalQtyExisting = 0.0;
        $totalQtyNew = 0.0;

        foreach ($bySku as $key => $row) {
            $cantidad = (float) $row['cantidad'];
            $producto = $products->get($key);

            if ($producto !== null) {
                $existing[$key] = [
                    'producto_id' => $producto->producto_id,
                    'sku' => $producto->producto_sku,
                    'nombre' => $producto->producto_nombre,
                    'cantidad_total' => $cantidad,
                    'stock_actual' => (float) $producto->producto_stock_actual,
                    'categoria_bd' => $producto->categoria?->categoria_nombre,
                ];
                $totalQtyExisting += $cantidad;

                continue;
            }

            $categoriaId = isset($options['categoria_id']) ? (int) $options['categoria_id'] : null;
            $categoriaTexto = trim((string) ($row['categoria_texto'] ?? ''));
            if ($categoriaId === null && $categoriaTexto !== '') {
                $categoriaId = $categories->get(mb_strtoupper($categoriaTexto))?->categoria_id;
            }

            if ($categoriaId === null && $categoriaTexto === '') {
                $errors[] = "SKU {$row['sku']}: no se pudo determinar categoría (columna vacía y sin categoria_id por defecto).";

                continue;
            }

            $new[$key] = [
                'sku' => $row['sku'],
                'nombre' => $row['nombre'],
                'referencia' => $row['referencia'],
                'cantidad_total' => $cantidad,
                'categoria_id' => $categoriaId,
                'categoria_texto' => $categoriaTexto !== '' ? $categoriaTexto : null,
            ];
            $totalQtyNew += $cantidad;
        }

        return [
            'existing' => $existing,
            'new' => $new,
            'errors' => $errors,
            'summary' => [
                'total_rows' => count($rows),
                'valid_skus' => count($bySku),
                'existing_products_count' => count($existing),
                'new_products_count' => count($new),
                'total_qty_existing' => $totalQtyExisting,
                'total_qty_new' => $totalQtyNew,
            ],
        ];
    }

    public function applyImport(
        array $preview,
        array $options,
        ?int $userId,
        string $idempotencyKey,
        string $requestHash,
        string $fileSha256,
    ): array {
        if (($preview['errors'] ?? []) !== []) {
            throw new DomainException('La importación contiene errores. Corrija el archivo antes de aplicarla.');
        }

        $existingImport = InventarioImportacion::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingImport !== null) {
            return $this->replayOrConflict($existingImport, $requestHash);
        }

        try {
            return DB::transaction(function () use ($preview, $options, $userId, $idempotencyKey, $requestHash, $fileSha256) {
                $newRows = $preview['new'] ?? [];
                $categoryIds = [];
                foreach ($newRows as $key => $row) {
                    if ($row['categoria_id'] !== null) {
                        $categoryIds[$key] = (int) $row['categoria_id'];

                        continue;
                    }

                    $categoryName = trim((string) ($row['categoria_texto'] ?? ''));
                    if ($categoryName === '') {
                        throw new DomainException("SKU {$row['sku']}: no tiene categoría.");
                    }

                    $category = Categoria::firstOrCreate(
                        ['categoria_nombre' => $categoryName],
                        [
                            'categoria_tipo' => 'repuesto',
                            'categoria_descripcion' => 'Generada automáticamente desde importación Siigo',
                        ],
                    );
                    $categoryIds[$key] = (int) $category->categoria_id;
                }

                $unidad = (string) ($options['unidad_medida'] ?? 'unidad');
                $alerta = (float) ($options['alerta_stock_minimo'] ?? 5);
                $createdProducts = 0;
                $productsThatAppearedAfterPreview = 0;
                foreach ($newRows as $key => $row) {
                    $producto = Producto::firstOrCreate(
                        ['producto_sku' => $row['sku']],
                        [
                            'categoria_id' => $categoryIds[$key],
                            'referencia_fabrica' => $row['referencia'] ?: null,
                            'producto_nombre' => $row['nombre'],
                            'producto_unidad_medida' => $unidad,
                            'producto_stock_actual' => 0,
                            'producto_alerta_stock_minimo' => $alerta,
                            'producto_precio_costo' => 0,
                            'producto_ubicacion' => null,
                        ],
                    );

                    if ($producto->wasRecentlyCreated) {
                        $createdProducts++;
                    } else {
                        $productsThatAppearedAfterPreview++;
                    }
                }

                $allRows = [...($preview['existing'] ?? []), ...$newRows];
                $products = Producto::query()
                    ->whereIn('producto_sku', array_column($allRows, 'sku'))
                    ->orderBy('producto_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(static fn (Producto $producto): string => mb_strtoupper($producto->producto_sku));

                // Keep the global lock order aligned with the ledger projector:
                // products first, then warehouses and their balances.
                $bodega = Bodega::query()->lockForUpdate()->find((int) $options['bodega_id']);
                if ($bodega === null) {
                    throw new DomainException('La bodega seleccionada ya no existe.');
                }

                $import = InventarioImportacion::create([
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'file_sha256' => $fileSha256,
                    'bodega_id' => $bodega->bodega_id,
                    'usuario_id' => $userId,
                    'estado' => 'procesando',
                ]);

                $movementsCreated = 0;
                foreach ($allRows as $row) {
                    $producto = $products->get(mb_strtoupper((string) $row['sku']));
                    if ($producto === null) {
                        throw new DomainException("El producto {$row['sku']} cambió durante la importación.");
                    }

                    TransaccionInventario::create([
                        'producto_id' => $producto->producto_id,
                        'bodega_id' => $bodega->bodega_id,
                        'usuario_id' => $userId,
                        'transaccion_tipo' => 'ingreso',
                        'transaccion_cantidad' => (float) $row['cantidad_total'],
                        'transaccion_motivo' => 'Importación compras Siigo',
                        'transaccion_referencia_type' => 'compra_import',
                        'transaccion_referencia_id' => $import->id,
                        'transaccion_notas' => 'Carga masiva transaccional desde archivo Siigo',
                    ]);
                    $movementsCreated++;
                }

                $result = [
                    'message' => 'Importación aplicada.',
                    'import_id' => $import->id,
                    'idempotency_key' => $idempotencyKey,
                    'created_products' => $createdProducts,
                    'updated_products' => count($preview['existing'] ?? []) + $productsThatAppearedAfterPreview,
                    'movements_created' => $movementsCreated,
                    'errors' => [],
                    'idempotent_replay' => false,
                ];

                $import->update(['estado' => 'completada', 'resultado' => $result]);

                return $result;
            });
        } catch (QueryException $exception) {
            $existingImport = InventarioImportacion::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingImport !== null) {
                return $this->replayOrConflict($existingImport, $requestHash);
            }

            throw $exception;
        }
    }

    private function replayOrConflict(InventarioImportacion $import, string $requestHash): array
    {
        if (! hash_equals((string) $import->request_hash, $requestHash)) {
            throw new InventoryImportConflictException(
                'La clave de idempotencia ya fue usada con otro archivo, bodega u opciones.',
            );
        }

        if ($import->estado !== 'completada' || ! is_array($import->resultado)) {
            throw new InventoryImportConflictException('La importación con esta clave todavía está en proceso.');
        }

        return [...$import->resultado, 'idempotent_replay' => true];
    }
}
