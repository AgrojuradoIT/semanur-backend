<?php

namespace Tests\Feature\InventoryOrders;

use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Prueba opt-in de semántica real MySQL/MariaDB (unique nullable, FK RESTRICT y
 * CHECK). SQLite no demuestra esas garantías.
 *
 * Configurar PEDIDO_DUAL_ENGINE_DSN (por ejemplo mysql:host=127.0.0.1;dbname=semanur_io_probe)
 * junto a PEDIDO_DUAL_ENGINE_USER y PEDIDO_DUAL_ENGINE_PASSWORD. Sin DSN se omite
 * limpiamente. Nunca simula resultados.
 */
class PedidoDualEngineTest extends TestCase
{
    private ?PDO $pdo = null;

    /** @var list<string> */
    private array $tables = [];

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            foreach ($this->tables as $table) {
                $this->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            }
            $this->pdo = null;
        }

        $this->tables = [];

        parent::tearDown();
    }

    public function test_dual_engine_semantics_are_opt_in(): void
    {
        $dsn = $this->env('PEDIDO_DUAL_ENGINE_DSN');

        if ($dsn === null) {
            $this->markTestSkipped(
                'Opt-in omitido: configure PEDIDO_DUAL_ENGINE_DSN/USER/PASSWORD para probar MySQL/MariaDB real.',
            );
        }

        if (str_starts_with(strtolower($dsn), 'sqlite:')) {
            $this->fail('PEDIDO_DUAL_ENGINE_DSN debe apuntar a MySQL o MariaDB, no a SQLite.');
        }

        $this->pdo = new PDO(
            $dsn,
            $this->env('PEDIDO_DUAL_ENGINE_USER') ?? '',
            $this->env('PEDIDO_DUAL_ENGINE_PASSWORD') ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        $this->assertNotSame('', $version, 'El servidor dual-engine debe reportar su versión.');

        $suffix = substr(bin2hex(random_bytes(6)), 0, 10);
        $parent = "io_probe_parent_{$suffix}";
        $child = "io_probe_child_{$suffix}";
        $this->tables = [$child, $parent];

        $this->pdo->exec(
            "CREATE TABLE `{$parent}` ("
            .'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            .'etiqueta VARCHAR(40) NULL'
            .') ENGINE=InnoDB',
        );

        $this->pdo->exec(
            "CREATE TABLE `{$child}` ("
            .'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            .'parent_id BIGINT UNSIGNED NULL, '
            .'pedido_linea_id BIGINT UNSIGNED NULL, '
            .'cantidad DECIMAL(10,2) NULL, '
            .'UNIQUE KEY io_probe_pedido_linea_unique (pedido_linea_id), '
            ."CONSTRAINT io_probe_parent_fk FOREIGN KEY (parent_id) REFERENCES `{$parent}` (id) ON DELETE RESTRICT, "
            .'CONSTRAINT io_probe_cantidad_check CHECK (cantidad IS NULL OR cantidad > 0)'
            .') ENGINE=InnoDB',
        );

        $insert = $this->pdo->prepare(
            "INSERT INTO `{$child}` (parent_id, pedido_linea_id, cantidad) VALUES (?, ?, ?)",
        );

        $insert->execute([null, null, null]);
        $insert->execute([null, null, null]);

        $this->assertSame(
            2,
            (int) $this->pdo->query("SELECT COUNT(*) FROM `{$child}` WHERE pedido_linea_id IS NULL")->fetchColumn(),
            'El índice único nullable debe admitir múltiples NULL.',
        );

        $insert->execute([null, 7, '4.00']);

        $duplicateRejected = false;

        try {
            $insert->execute([null, 7, '4.00']);
        } catch (PDOException) {
            $duplicateRejected = true;
        }

        $this->assertTrue($duplicateRejected, 'El índice único debe rechazar pedido_linea_id duplicado.');

        $this->pdo->exec("INSERT INTO `{$parent}` (etiqueta) VALUES ('probe')");
        $parentId = (int) $this->pdo->lastInsertId();

        $restrictRejected = false;

        try {
            $this->pdo->exec("DELETE FROM `{$parent}` WHERE id = {$parentId}");
        } catch (PDOException) {
            $restrictRejected = true;
        }

        $this->assertTrue($restrictRejected, 'La FK ON DELETE RESTRICT debe impedir borrar el padre referenciado.');

        $negativeRejected = false;

        try {
            $insert->execute([null, null, '-1.00']);
        } catch (PDOException) {
            $negativeRejected = true;
        }

        $this->assertTrue($negativeRejected, 'El CHECK debe rechazar cantidades negativas.');
    }

    private function env(string $key): ?string
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
