<?php

namespace Tests\Feature\InventoryOrders;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PedidoContabilidadRolSeedTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'migrations/2026_09_10_100000_seed_contabilidad_role.php';

    public function test_seed_clones_jefe_taller_modules_and_adds_only_accounting_capabilities(): void
    {
        $jefe = $this->permisosDe('jefe_taller');
        $contabilidad = $this->permisosDe('contabilidad');

        $this->assertNotEmpty($contabilidad);

        foreach ($jefe as $permiso) {
            $this->assertContains($permiso, $contabilidad, "Contabilidad debía heredar {$permiso}");
        }

        foreach ([
            'pedidos.read',
            'pedidos.create',
            'pedidos.document.write',
            'pedidos.publish',
            'pedidos.discrepancy.respond',
            'pedidos.cancel',
        ] as $capacidad) {
            $this->assertContains($capacidad, $contabilidad, "Falta la capacidad nueva {$capacidad}");
        }

        foreach ([
            'pedidos.receive',
            'pedidos.catalog.complete',
            'pedidos.discrepancy.authorize',
        ] as $capacidadDenegada) {
            $this->assertNotContains(
                $capacidadDenegada,
                $contabilidad,
                "Contabilidad no debe recibir {$capacidadDenegada}",
            );
        }
    }

    public function test_seed_is_idempotent_and_forgets_the_role_cache(): void
    {
        Cache::put('role_permisos_contabilidad', ['stale'], 3600);
        $this->assertTrue(Cache::has('role_permisos_contabilidad'));

        $migration = require database_path(self::MIGRATION_PATH);
        $migration->up();
        $migration->up();

        $this->assertSame(
            1,
            DB::table('role_permisos')->where('role', 'contabilidad')->count(),
        );
        $this->assertFalse(Cache::has('role_permisos_contabilidad'));
    }

    public function test_contabilidad_role_defaults_include_jefe_taller_modules(): void
    {
        $jefe = User::factory()->create(['role' => 'jefe_taller']);
        $contabilidad = User::factory()->create(['role' => 'contabilidad']);

        $defaultsContabilidad = $contabilidad->roleDefaults();

        foreach ($jefe->roleDefaults() as $permiso) {
            $this->assertContains($permiso, $defaultsContabilidad);
        }

        $this->assertContains('pedidos.read', $defaultsContabilidad);
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
