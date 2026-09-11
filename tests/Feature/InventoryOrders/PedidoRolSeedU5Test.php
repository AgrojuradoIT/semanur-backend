<?php

namespace Tests\Feature\InventoryOrders;

use App\Policies\PedidoInventarioPolicy;
use App\Services\Inventory\Orders\PedidoAlertaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\InventoryOrders\Concerns\PedidoPublicacionFixtures;
use Tests\TestCase;

/**
 * Semilla U5 de capacidades: auxiliar_bodega recibe/lee/completa catálogo y
 * jefe_taller obtiene lectura de supervisión, sin quitar permisos previos.
 */
class PedidoRolSeedU5Test extends TestCase
{
    use PedidoPublicacionFixtures, RefreshDatabase;

    private const MIGRATION_PATH = 'migrations/2026_09_10_110000_seed_pedidos_reception_capabilities.php';

    public function test_seed_agrega_capacidades_sin_quitar_permisos_existentes(): void
    {
        $auxiliar = $this->permisosDe('auxiliar_bodega');

        foreach (['inventario.write', 'movimientos.read', 'prestamos.write'] as $legado) {
            $this->assertContains($legado, $auxiliar, "El seed no debe quitar {$legado}");
        }

        foreach (['pedidos.read', 'pedidos.receive', 'pedidos.catalog.complete'] as $capacidad) {
            $this->assertContains($capacidad, $auxiliar, "Falta la capacidad {$capacidad} en auxiliar_bodega");
        }

        $jefe = $this->permisosDe('jefe_taller');

        $this->assertContains('pedidos.read', $jefe);
        $this->assertContains('taller.write', $jefe);
        $this->assertNotContains('pedidos.receive', $jefe);
        $this->assertNotContains('pedidos.catalog.complete', $jefe);

        $contabilidad = $this->permisosDe('contabilidad');

        $this->assertNotContains('pedidos.receive', $contabilidad);
        $this->assertNotContains('pedidos.catalog.complete', $contabilidad);
    }

    public function test_seed_es_idempotente_y_limpia_la_cache_de_los_roles(): void
    {
        Cache::put('role_permisos_auxiliar_bodega', ['stale'], 3600);
        Cache::put('role_permisos_jefe_taller', ['stale'], 3600);

        $migration = require database_path(self::MIGRATION_PATH);
        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('role_permisos')->where('role', 'auxiliar_bodega')->count());
        $this->assertSame(1, DB::table('role_permisos')->where('role', 'jefe_taller')->count());
        $this->assertFalse(Cache::has('role_permisos_auxiliar_bodega'));
        $this->assertFalse(Cache::has('role_permisos_jefe_taller'));
    }

    public function test_resolucion_de_receptores_respeta_la_matriz_sin_bypass_de_admin(): void
    {
        $servicio = app(PedidoAlertaService::class);

        $this->assertTrue($servicio->usuarioPuedeRecibir($this->usuario('auxiliar_bodega')));
        $this->assertFalse($servicio->usuarioPuedeRecibir($this->usuario('jefe_taller')));
        $this->assertFalse($servicio->usuarioPuedeRecibir($this->usuario('contabilidad')));
        $this->assertFalse($servicio->usuarioPuedeRecibir($this->usuario('mecanico')));
        $this->assertFalse(
            $servicio->usuarioPuedeRecibir($this->usuario('admin')),
            'Admin no hereda recepción sin opt-in explícito.',
        );

        config(['inventory_orders.admin_can_receive' => true]);

        $this->assertTrue($servicio->usuarioPuedeRecibir($this->usuario('admin')));

        config(['inventory_orders.admin_can_receive' => false]);

        $this->assertTrue(
            PedidoInventarioPolicy::usuarioTieneCapacidad(
                $this->usuario('auxiliar_bodega'),
                PedidoInventarioPolicy::CAP_READ,
            ),
        );
    }

    /**
     * @return array<int, string>
     */
    private function permisosDe(string $role): array
    {
        $raw = DB::table('role_permisos')->where('role', $role)->value('permisos');

        $this->assertNotNull($raw, "Falta la fila role_permisos de {$role}");

        $decoded = json_decode((string) $raw, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
