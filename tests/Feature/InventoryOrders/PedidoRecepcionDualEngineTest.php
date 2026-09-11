<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\PedidoEstado;
use App\Models\Bodega;
use App\Models\Categoria;
use App\Models\Empleado;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoRecepcion;
use App\Models\PedidoRecepcionLinea;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use App\Models\User;
use App\Services\Inventory\Orders\PedidoRecepcionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Tests\TestCase;

/**
 * Prueba opt-in de concurrencia real en MySQL/MariaDB (U6; C03/C07).
 *
 * SQLite :memory: no demuestra locks ni concurrencia entre conexiones. Sin
 * `PEDIDO_RECEPCION_DUAL_ENGINE_DSN` la prueba se omite limpiamente. El DSN
 * debe apuntar a una base DESCARTABLE cuyo nombre contenga "probe": la prueba
 * ejecuta migrate:fresh sobre ella. Nunca se ejecuta automáticamente en CI.
 *
 * Uso:
 *   $env:PEDIDO_RECEPCION_DUAL_ENGINE_DSN = "mysql:host=127.0.0.1;port=3306;dbname=semanur_io_probe"
 *   $env:PEDIDO_RECEPCION_DUAL_ENGINE_USER = "<usuario>"
 *   $env:PEDIDO_RECEPCION_DUAL_ENGINE_PASSWORD = "<clave>"
 *   php artisan test --filter=PedidoRecepcionDualEngine
 */
class PedidoRecepcionDualEngineTest extends TestCase
{
    private bool $conexionConfigurada = false;

    protected function tearDown(): void
    {
        if ($this->conexionConfigurada) {
            DB::purge('io_probe_second');
            DB::purge('io_probe');
            config(['database.default' => 'sqlite']);
            $this->conexionConfigurada = false;
        }

        parent::tearDown();
    }

    public function test_concurrencia_real_de_confirmacion_con_dos_conexiones(): void
    {
        $dsn = $this->env('PEDIDO_RECEPCION_DUAL_ENGINE_DSN');

        if ($dsn === null) {
            $this->markTestSkipped(
                'Opt-in omitido: configure PEDIDO_RECEPCION_DUAL_ENGINE_DSN/USER/PASSWORD para probar MySQL/MariaDB real.',
            );
        }

        if (str_starts_with(strtolower($dsn), 'sqlite:')) {
            $this->fail('PEDIDO_RECEPCION_DUAL_ENGINE_DSN debe apuntar a MySQL o MariaDB, no a SQLite.');
        }

        $config = $this->configurarConexiones($dsn);
        $this->conexionConfigurada = true;

        Artisan::call('migrate:fresh', ['--force' => true]);

        [$pedido, $linea, $producto, $recepcion, $receptor] = $this->fixtures();
        $servicio = $this->app->make(PedidoRecepcionService::class);

        // Conexión B retiene el lock de la cabecera mientras la confirmación
        // intenta tomarlo: serialización real, sin inversión de orden.
        $segunda = DB::connection('io_probe_second');
        $segunda->beginTransaction();
        $segunda->select('SELECT pedido_id FROM pedidos_inventario WHERE pedido_id = ? FOR UPDATE', [$pedido->pedido_id]);

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $bloqueada = false;

        try {
            $servicio->confirmar($pedido, $recepcion, $receptor, 1, (string) Str::uuid());
        } catch (QueryException $exception) {
            $bloqueada = true;
            $this->assertStringContainsString('Lock wait timeout', $exception->getMessage());
        }

        $this->assertTrue($bloqueada, 'La confirmación debía quedar bloqueada por el lock ajeno.');

        // Con la transacción ajena aún abierta, nada se escribió.
        $this->assertSame(0, TransaccionInventario::query()->count());
        $this->assertSame('borrador', PedidoRecepcion::query()->findOrFail($recepcion->recepcion_id)->estado);
        $this->assertSame('0.00', $this->canon($producto->fresh()->producto_stock_actual));

        $segunda->rollBack();

        // Liberado el lock, la misma confirmación procede una sola vez.
        $resultado = $servicio->confirmar($pedido->fresh(), $recepcion, $receptor, 1, (string) Str::uuid());

        $this->assertFalse($resultado['replay']);
        $this->assertSame('recibido', $pedido->fresh()->estado);
        $this->assertSame(1, TransaccionInventario::query()->count());
        $this->assertSame('4.00', $this->canon($producto->fresh()->producto_stock_actual));
        $this->assertSame('confirmada', PedidoRecepcion::query()->findOrFail($recepcion->recepcion_id)->estado);

        // El unique real sobre pedido_linea_id impide un segundo ingreso,
        // incluso con otra conexión y otro UUID de operación.
        $pdo = DB::connection('io_probe_second')->getPdo();

        $segundoIngresoRechazado = false;

        try {
            $pdo->prepare(
                'INSERT INTO transaccion_inventarios '
                .'(producto_id, bodega_id, usuario_id, transaccion_tipo, transaccion_cantidad, transaccion_motivo, pedido_linea_id) '
                .'VALUES (?, ?, ?, ?, ?, ?, ?)',
            )->execute([
                $producto->producto_id,
                $pedido->bodega_id,
                $receptor->id,
                'ingreso',
                '4.00',
                'Intento duplicado de prueba',
                $linea->linea_id,
            ]);
        } catch (PDOException) {
            $segundoIngresoRechazado = true;
        }

        $this->assertTrue($segundoIngresoRechazado, 'El unique(pedido_linea_id) debe rechazar un segundo ingreso.');
        $this->assertSame(1, TransaccionInventario::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function configurarConexiones(string $dsn): array
    {
        $partes = [];

        foreach (array_slice(explode(';', $dsn), 0) as $chunk) {
            if (! str_contains($chunk, '=')) {
                continue;
            }

            [$clave, $valor] = explode('=', $chunk, 2);
            $partes[strtolower(trim($clave))] = trim($valor);
        }

        $base = $partes['dbname'] ?? '';

        if (! str_contains(strtolower($base), 'probe')) {
            $this->markTestSkipped(
                'Por seguridad, la base dual-engine de recepción debe contener "probe" en el nombre (se ejecuta migrate:fresh).',
            );
        }

        $config = [
            'driver' => 'mysql',
            'host' => $partes['host'] ?? '127.0.0.1',
            'port' => $partes['port'] ?? '3306',
            'database' => $base,
            'username' => $this->env('PEDIDO_RECEPCION_DUAL_ENGINE_USER') ?? '',
            'password' => $this->env('PEDIDO_RECEPCION_DUAL_ENGINE_PASSWORD') ?? '',
            'charset' => $partes['charset'] ?? 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
        ];

        config([
            'database.connections.io_probe' => $config,
            'database.connections.io_probe_second' => $config,
            'database.default' => 'io_probe',
        ]);

        DB::purge('io_probe');
        DB::purge('io_probe_second');

        return $config;
    }

    /**
     * @return array{0: PedidoInventario, 1: PedidoInventarioLinea, 2: Producto, 3: PedidoRecepcion, 4: User}
     */
    private function fixtures(): array
    {
        $creador = User::factory()->create(['role' => 'contabilidad']);
        $receptor = User::factory()->create(['role' => 'auxiliar_bodega']);
        $empleado = Empleado::create(['nombres' => 'Receptor', 'apellidos' => 'Dual', 'estado' => 'activo', 'user_id' => $receptor->id]);

        $bodega = Bodega::create(['nombre' => 'Bodega Dual', 'tipo' => 'estandar']);
        $categoria = Categoria::create(['categoria_nombre' => 'Categoría Dual', 'categoria_tipo' => 'repuesto']);
        $producto = Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'SKU-DUAL-'.Str::upper(Str::random(6)),
            'producto_nombre' => 'Producto Dual',
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => 0,
        ]);

        $pedido = PedidoInventario::create([
            'bodega_id' => $bodega->bodega_id,
            'created_by' => $creador->id,
            'proveedor_nombre_confirmado' => 'Proveedor Dual',
        ]);

        $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION);

        $linea = PedidoInventarioLinea::create([
            'pedido_id' => $pedido->pedido_id,
            'orden' => 1,
            'sku_raw' => $producto->producto_sku,
            'sku_confirmado' => $producto->producto_sku,
            'descripcion_confirmada' => 'Producto Dual',
            'cantidad_esperada' => '4.00',
            'producto_id' => $producto->producto_id,
        ]);

        $recepcion = PedidoRecepcion::create([
            'pedido_id' => $pedido->pedido_id,
            'actor_user_id' => $receptor->id,
            'receptor_empleado_id' => $empleado->id,
        ]);

        PedidoRecepcionLinea::create([
            'recepcion_id' => $recepcion->recepcion_id,
            'pedido_linea_id' => $linea->linea_id,
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'conforme',
            'esperado_snapshot' => '4.00',
            'actor_user_id' => $receptor->id,
            'receptor_empleado_id' => $empleado->id,
        ]);

        return [$pedido, $linea, $producto, $recepcion, $receptor];
    }

    private function canon(mixed $valor): string
    {
        if ($valor === null) {
            return '0.00';
        }

        return number_format((float) $valor, 2, '.', '');
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
