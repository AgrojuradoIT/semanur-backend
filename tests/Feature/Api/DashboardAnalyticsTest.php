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

    // ─── DA-1.2 / SPEC-001 TDD: getOtStats aggregation (PR1 task 1.2) ─
    // Each test seeds an OrdenTrabajo via direct Model::create() and asserts
    // the expected porEstado / abiertas / prioridades / sinMecanico counts.
    // The stub currently returns zeros, so these tests are RED until the
    // real aggregation logic lands in AnalyticsApiController::getOtStats().

    private function vehiculoForOt(): Vehiculo
    {
        return Vehiculo::create([
            'placa' => 'TES' . str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT),
            'tipo' => 'Camion',
            'categoria' => 'Carga',
            'tipo_combustible' => 'Diesel',
            'metodo_seguimiento' => 'kilometraje',
            'marca' => 'TestMarca',
            'modelo' => 'TestModelo',
        ]);
    }

    private function mecanicoEmpleadoId(): int
    {
        // Create a User + matching Empleado so the FK on orden_trabajos.mecanico_asignado_id
        // (which references empleados.id after migration 2026_05_26_075600) is satisfied.
        $user = User::factory()->create();
        $empleado = Empleado::firstOrCreate(
            ['user_id' => $user->id],
            [
                'documento' => (string) (10000000 + $user->id),
                'nombres' => 'Test',
                'apellidos' => 'Mecanico',
            ]
        );
        return $empleado->id;
    }

    private function makeOt(array $overrides): OrdenTrabajo
    {
        return OrdenTrabajo::create(array_merge([
            'vehiculo_id' => $this->vehiculoForOt()->vehiculo_id,
            'fecha_inicio' => '2026-07-01',
            'estado' => 'Abierta',
            'prioridad' => 'Media',
            'descripcion' => 'OT de prueba para tests de dashboard',
        ], $overrides));
    }

    // ─── DA-1.2.a: porEstado has the 5 keys as integers, non-zero when DB has data

    public function test_ot_stats_groups_by_estado_with_5_keys_zero_filled(): void
    {
        Sanctum::actingAs($this->userWithAnalitica());

        // Seed OTs spread across all 5 estados (1 each minimum)
        $eachEstado = ['Abierta', 'En Progreso', 'Pendiente Auditoria', 'Aprobada', 'Cerrada'];
        foreach ($eachEstado as $e) {
            $this->makeOt(['estado' => $e]);
        }

        $response = $this->dashboardAll();
        $response->assertOk();
        $porEstado = $response->json('otStats.porEstado');

        // Exactly the 5 expected keys, in the canonical order.
        $this->assertSame(
            array_keys($porEstado),
            self::OT_ESTADOS,
            'porEstado keys must match OT_ESTADOS order'
        );

        // Each value is an integer (NOT null), and reflects the seeded counts.
        foreach (self::OT_ESTADOS as $estado) {
            $this->assertIsInt($porEstado[$estado], "porEstado[$estado] must be int");
            $this->assertSame(1, $porEstado[$estado], "porEstado[$estado] must be 1 (one seed)");
        }
    }

    // ─── DA-1.2.b: abiertas counts ONLY open states (Abierta + En Progreso), excludes Cerrada/Pendiente/Aprobada

    public function test_ot_stats_abiertas_counts_only_open_states(): void
    {
        Sanctum::actingAs($this->userWithAnalitica());

        // 3 Abierta + 2 En Progreso + 2 Cerrada = total 7, but abiertas must be 5.
        for ($i = 0; $i < 3; $i++) {
            $this->makeOt(['estado' => 'Abierta']);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->makeOt(['estado' => 'En Progreso']);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->makeOt(['estado' => 'Cerrada']);
        }

        $response = $this->dashboardAll();
        $response->assertOk();

        $this->assertSame(5, $response->json('otStats.abiertas'), 'abiertas must be 5 (only Abierta + En Progreso)');
    }

    // ─── DA-1.2.c: prioridades counts ONLY open states (closed-state priority MUST NOT count)

    public function test_ot_stats_prioridades_count_only_for_open_states(): void
    {
        Sanctum::actingAs($this->userWithAnalitica());

        // 2 Alta Abierta + 1 Media Abierta + 1 Baja Abierta = 4 open
        // 3 Alta Cerrada = 3 closed (MUST NOT count)
        // Expected: Alta=2, Media=1, Baja=1 (NOT Alta=5).
        $mecanicoId = $this->mecanicoEmpleadoId();
        for ($i = 0; $i < 2; $i++) {
            $this->makeOt(['estado' => 'Abierta', 'prioridad' => 'Alta', 'mecanico_asignado_id' => $mecanicoId]);
        }
        $this->makeOt(['estado' => 'Abierta', 'prioridad' => 'Media', 'mecanico_asignado_id' => $mecanicoId]);
        $this->makeOt(['estado' => 'Abierta', 'prioridad' => 'Baja', 'mecanico_asignado_id' => $mecanicoId]);
        for ($i = 0; $i < 3; $i++) {
            $this->makeOt(['estado' => 'Cerrada', 'prioridad' => 'Alta']);
        }

        $response = $this->dashboardAll();
        $response->assertOk();

        $this->assertSame(2, $response->json('otStats.prioridades.Alta'), 'prioridades.Alta must count ONLY open (Abierta)');
        $this->assertSame(1, $response->json('otStats.prioridades.Media'), 'prioridades.Media must be 1');
        $this->assertSame(1, $response->json('otStats.prioridades.Baja'), 'prioridades.Baja must be 1');
    }

    // ─── DA-1.2.d: sinMecanico counts NULL mecanico_asignado_id

    public function test_ot_stats_sin_mecanico_counts_null_mecanico_asignado_id(): void
    {
        Sanctum::actingAs($this->userWithAnalitica());

        // 2 OTs with mecanico assigned + 1 with NULL = sinMecanico must be exactly 1.
        $mecanicoId = $this->mecanicoEmpleadoId();
        $this->makeOt(['estado' => 'Abierta', 'mecanico_asignado_id' => $mecanicoId]);
        $this->makeOt(['estado' => 'En Progreso', 'mecanico_asignado_id' => $mecanicoId]);
        $this->makeOt(['estado' => 'Abierta', 'mecanico_asignado_id' => null]);

        $response = $this->dashboardAll();
        $response->assertOk();

        $this->assertSame(1, $response->json('otStats.sinMecanico'), 'sinMecanico must count only NULL mecanico_asignado_id');
    }
}
