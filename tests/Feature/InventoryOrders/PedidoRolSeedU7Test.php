<?php

namespace Tests\Feature\InventoryOrders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\InventoryOrders\Concerns\PedidoPublicacionFixtures;
use Tests\TestCase;

/**
 * Semilla U7: la auxiliar de bodega suma responder y firmar desfases (D1),
 * fusionando sin quitar permisos y sin tocar contabilidad.
 */
class PedidoRolSeedU7Test extends TestCase
{
    use PedidoPublicacionFixtures, RefreshDatabase;

    private const MIGRATION_PATH = 'migrations/2026_09_10_120000_seed_pedidos_discrepancy_capabilities.php';

    public function test_seed_mergea_capacidades_sin_quitar_permisos(): void
    {
        $auxiliar = $this->permisosDe('auxiliar_bodega');

        foreach ([
            'pedidos.read',
            'pedidos.receive',
            'pedidos.catalog.complete',
            'pedidos.discrepancy.respond',
            'pedidos.discrepancy.authorize',
            'inventario.write',
        ] as $capacidad) {
            $this->assertContains($capacidad, $auxiliar, "Falta {$capacidad} en auxiliar_bodega");
        }

        $contabilidad = $this->permisosDe('contabilidad');

        $this->assertContains('pedidos.discrepancy.respond', $contabilidad);
        $this->assertNotContains('pedidos.discrepancy.authorize', $contabilidad);

        $jefe = $this->permisosDe('jefe_taller');

        $this->assertNotContains('pedidos.discrepancy.authorize', $jefe);
        $this->assertNotContains('pedidos.discrepancy.respond', $jefe);
    }

    public function test_seed_es_idempotente_y_limpia_la_cache(): void
    {
        Cache::put('role_permisos_auxiliar_bodega', ['stale'], 3600);

        $migration = require database_path(self::MIGRATION_PATH);
        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('role_permisos')->where('role', 'auxiliar_bodega')->count());
        $this->assertFalse(Cache::has('role_permisos_auxiliar_bodega'));

        $permisos = $this->permisosDe('auxiliar_bodega');
        $this->assertSame(count($permisos), count(array_unique($permisos)), 'El seed no debe duplicar capacidades');
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
