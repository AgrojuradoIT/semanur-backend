<?php

namespace App\Console\Commands;

use App\Models\PedidoDocumento;
use App\Services\Inventory\Orders\PedidoDocumentoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Recolector acotado de objetos huérfanos del pipeline de documentos (U3).
 *
 * Reglas:
 * - Solo considera archivos bajo el prefijo `pedidos/` en el disco privado
 *   configurado.
 * - Nunca toca un archivo referenciado por pedido_documentos.storage_key ni su
 *   derivado `preview.jpg` de la misma carpeta.
 * - Solo borra objetos con antigüedad mayor a documents.orphan_ttl_hours.
 * - Dry-run por defecto; `--force` ejecuta el borrado. `--limit` acota cuántos
 *   objetos se procesan por corrida.
 *
 * U9 es quien decide el scheduling; este comando no se registra en el
 * scheduler todavía.
 */
class ReapPedidoDocumentOrphans extends Command
{
    protected $signature = 'inventory-orders:reap-document-orphans
                            {--limit=200 : Máximo de objetos huérfanos a procesar en esta corrida}
                            {--force : Borra realmente los huérfanos; sin esta bandera es dry-run}';

    protected $description = 'Lista (dry-run) o elimina objetos huérfanos acotados de documentos de pedidos sin referencia en BD';

    public function handle(): int
    {
        $disk = (string) config('inventory_orders.documents.disk', 'local');

        if ($disk === '' || $disk === 'public') {
            $this->error('El disco configurado para documentos debe ser privado; se rechaza public/.');

            return self::FAILURE;
        }

        $ttlHours = max(1, (int) config('inventory_orders.documents.orphan_ttl_hours', 24));
        $limit = (int) $this->option('limit');

        if ($limit < 1) {
            $this->error('--limit debe ser un entero mayor o igual a 1.');

            return self::FAILURE;
        }

        $limit = min($limit, 5000);
        $cutoff = now()->subHours($ttlHours)->getTimestamp();
        $referenciados = $this->referencedPaths();
        $storage = Storage::disk($disk);
        $huerfanos = [];

        foreach ($storage->allFiles('pedidos') as $path) {
            if (isset($referenciados[$path])) {
                continue;
            }

            $modified = $storage->lastModified($path);

            if (! is_int($modified) || $modified >= $cutoff) {
                continue;
            }

            $huerfanos[] = [
                'path' => $path,
                'modified' => $modified,
                'size' => (int) $storage->size($path),
            ];

            if (count($huerfanos) >= $limit) {
                break;
            }
        }

        if ($huerfanos === []) {
            $this->info(sprintf('Sin huérfanos elegibles (TTL %d h, límite %d).', $ttlHours, $limit));

            return self::SUCCESS;
        }

        $this->table(
            ['Ruta', 'Antigüedad (h)', 'Bytes'],
            array_map(fn (array $item): array => [
                $item['path'],
                (string) (int) floor((time() - $item['modified']) / 3600),
                (string) $item['size'],
            ], $huerfanos),
        );

        if (! $this->option('force')) {
            $this->warn(sprintf(
                '[DRY-RUN] %d huérfano(s) elegible(s). Ejecute con --force para eliminar.',
                count($huerfanos),
            ));

            return self::SUCCESS;
        }

        $eliminados = 0;

        foreach ($huerfanos as $item) {
            // Guarda final: jamás borrar algo que aparezca referenciado.
            if (isset($referenciados[$item['path']])) {
                continue;
            }

            try {
                if ($storage->delete($item['path'])) {
                    $eliminados++;
                }
            } catch (Throwable $exception) {
                $this->error(sprintf('No se pudo eliminar %s: %s', $item['path'], $exception->getMessage()));
            }
        }

        $this->info(sprintf('Se eliminaron %d de %d huérfano(s) elegible(s).', $eliminados, count($huerfanos)));

        return self::SUCCESS;
    }

    /**
     * Conjunto protegido: cada storage_key referenciado más el derivado de su
     * carpeta. El índice por ruta da consultas O(1) por archivo escaneado.
     *
     * @return array<string, true>
     */
    private function referencedPaths(): array
    {
        $protegidos = [];

        PedidoDocumento::query()
            ->whereNotNull('storage_key')
            ->pluck('storage_key')
            ->each(function (mixed $key) use (&$protegidos): void {
                $key = (string) $key;

                if ($key === '') {
                    return;
                }

                $protegidos[$key] = true;
                $protegidos[PedidoDocumentoService::derivativePathFor($key)] = true;
            });

        return $protegidos;
    }
}
