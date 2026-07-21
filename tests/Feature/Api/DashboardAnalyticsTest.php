<?php

namespace Tests\Feature\Api;

use App\Models\Empleado;
use App\Models\OrdenTrabajo;
use App\Models\PrestamoHerramienta;
use App\Models\PreoperacionalDailyForm;
use App\Models\PreoperacionalSemana;
use App\Models\PreoperacionalTemplate;
use App\Models\Producto;
use App\Models\RegistroCombustible;
use App\Models\TransaccionInventario;
use App\Models\User;
use App\Models\Vehiculo;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the extended BFF contract of GET /api/dashboard/all.
 *
 * Mirrors PreoperacionalV2Test conventions (RefreshDatabase + Sanctum::actingAs).
 * Covers dashboard-analytics SPEC-001 through SPEC-006 (DA-1..DA-6).
 */
class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const OT_ESTADOS = ['Abierta', 'En Progreso', 'Pendiente Auditoria', 'Aprobada', 'Cerrada'];
    private const OT_PRIORIDADES = ['Alta', 'Media', 'Baja'];
    private const OPEN_OT_ESTADOS = ['Abierta', 'En Progreso'];

    /**
     * Bootstrap an authenticated user WITH analitica.read permission (non-admin to exercise the real path).
     */
    private function userWithAnalitica(): User
    {
        return User::factory()->create([
            'role' => 'operativo',
            'permisos' => ['analitica.read'],
        ]);
    }

    /**
     * Bootstrap an authenticated user WITHOUT analitica.read permission.
     */
    private function userWithoutAnalitica(): User
    {
        return User::factory()->create([
            'role' => 'operativo',
            'permisos' => [],
        ]);
    }

    private function dashboardAll()
    {
        return $this->getJson('/api/dashboard/all');
    }

    // ─── DA-6.1 / SPEC-006: Authorization ────────────────────────────────

    public function test_dashboard_returns_403_without_analitica_read_permission(): void
    {
        Sanctum::actingAs($this->userWithoutAnalitica());

        $response = $this->dashboardAll();

        $response->assertForbidden();
        $this->assertStringContainsString('analitica.read', $response->json('message'));
    }

    // ─── DA-1.3 / SPEC-001: Response contract with empty DB ───────────────

    public function test_dashboard_returns_200_with_zeros_and_empty_lists_on_empty_db(): void
    {
        Sanctum::actingAs($this->userWithAnalitica());

        $response = $this->dashboardAll();

        $response->assertOk();

        // The 5 new keys MUST be present.
        $response->assertJsonStructure([
            'otStats',
            'preopHoy',
            'loansStats',
            'lowStock',
            'alerts',
        ]);

        // otStats: 5 estados 0-filled, abiertas 0, prioridades 0, sinMecanico 0
        $ot = $response->json('otStats');
        foreach (self::OT_ESTADOS as $estado) {
            $this->assertSame(0, $ot['porEstado'][$estado] ?? -1, "porEstado[$estado] should be 0");
        }
        $this->assertSame(0, $ot['abiertas']);
        foreach (self::OT_PRIORIDADES as $prio) {
            $this->assertSame(0, $ot['prioridades'][$prio] ?? -1, "prioridades[$prio] should be 0");
        }
        $this->assertSame(0, $ot['sinMecanico']);

        // preopHoy: zero counts, empty pendientes
        $this->assertSame(0, $response->json('preopHoy.total'));
        $this->assertSame(0, $response->json('preopHoy.completados'));
        $this->assertSame([], $response->json('preopHoy.pendientes'));

        // loansStats: 0 / 0 / []
        $this->assertSame(0, $response->json('loansStats.activos'));
        $this->assertSame(0, $response->json('loansStats.envejecidos'));
        $this->assertSame([], $response->json('loansStats.items'));

        // lowStock: 0 / []
        $this->assertSame(0, $response->json('lowStock.count'));
        $this->assertSame([], $response->json('lowStock.items'));

        // alerts: total 0, counts 0-everywhere, items []
        $this->assertSame(0, $response->json('alerts.total'));
        $this->assertSame([], $response->json('alerts.items'));
        $alertsCounts = $response->json('alerts.counts');
        foreach (['docs_vencidos', 'docs_por_vencer', 'servicios', 'stock', 'prestamos'] as $k) {
            $this->assertSame(0, $alertsCounts[$k] ?? -1, "alerts.counts.$k should be 0");
        }
    }

    public function test_dashboard_preserves_existing_keys_when_extended(): void
    {
        Sanctum::actingAs($this->userWithAnalitica());

        $response = $this->dashboardAll();

        $response->assertOk();
        // Existing keys retained (dead-data fix on maintenance cost)
        $response->assertJsonStructure(['summary', 'fuelMonthly', 'maintenanceByVehicle', 'fuelStock', 'fuelHistory15Days']);
        $this->assertIsArray($response->json('summary'));
    }

    public function test_dashboard_never_500s_on_empty_database(): void
    {
        Sanctum::actingAs($this->userWithAnalitica());

        $response = $this->dashboardAll();

        $this->assertLessThan(500, $response->status(), 'Dashboard must never 500 on empty DB');
    }
}
