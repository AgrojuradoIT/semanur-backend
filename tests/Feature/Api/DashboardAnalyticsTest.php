<?php

namespace Tests\Feature\Api;

use App\Models\Categoria;
use App\Models\Empleado;
use App\Models\OrdenTrabajo;
use App\Models\PreoperacionalDailyForm;
use App\Models\PreoperacionalSemana;
use App\Models\PreoperacionalTemplate;
use App\Models\PrestamoHerramienta;
use App\Models\Producto;
use App\Models\RegistroCombustible;
use App\Models\TransaccionInventario;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\WorkSession;
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
    private int $otVehicleSequence = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

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
            'liveSessions' => ['total', 'items'],
            'recentActivity',
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

        $this->assertSame(0, $response->json('liveSessions.total'));
        $this->assertSame([], $response->json('liveSessions.items'));
        $this->assertSame([], $response->json('recentActivity'));
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
            'placa' => 'TES' . str_pad((string) ++$this->otVehicleSequence, 3, '0', STR_PAD_LEFT),
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

    public function test_ot_stats_sin_mecanico_excludes_closed_unassigned_work_orders(): void
    {
        Sanctum::actingAs($this->userWithAnalitica());

        $this->makeOt(['estado' => 'Abierta', 'mecanico_asignado_id' => null]);
        $this->makeOt(['estado' => 'Cerrada', 'mecanico_asignado_id' => null]);

        $response = $this->dashboardAll();

        $response->assertOk();
        $this->assertSame(1, $response->json('otStats.sinMecanico'), 'sinMecanico must exclude closed unassigned work orders');
    }

    // --- DA-3 / SPEC-003 TDD: getPreopHoy() uses the Bogota calendar day ---

    private function preopInspectorId(): int
    {
        $user = User::factory()->create();

        return Empleado::create([
            'user_id' => $user->id,
            'documento' => (string) (20000000 + $user->id),
            'nombres' => 'Inspector',
            'apellidos' => 'Preop',
        ])->id;
    }

    private function makePreopVehicle(string $suffix): Vehiculo
    {
        return Vehiculo::create([
            'placa' => 'PRE' . $suffix,
            'tipo' => 'Camion',
            'categoria' => 'Carga',
            'tipo_combustible' => 'Diesel',
            'metodo_seguimiento' => 'kilometraje',
            'marca' => 'TestMarca',
            'modelo' => 'TestModelo',
        ]);
    }

    private function makePreopSemana(
        Vehiculo $vehiculo,
        PreoperacionalTemplate $template,
        Carbon $fecha,
        string $diaSemana,
        bool $completado,
    ): PreoperacionalSemana {
        $semanaInicio = $fecha->copy()->startOfWeek(Carbon::MONDAY);
        $semana = PreoperacionalSemana::create([
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'template_id' => $template->id,
            'inspector_id' => $this->preopInspectorId(),
            'semana_inicio' => $semanaInicio->toDateString(),
            'semana_fin' => $semanaInicio->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            'semana_numero' => $semanaInicio->isoWeek,
            'semana_anio' => $semanaInicio->year,
            'vehiculo_marca' => $vehiculo->marca,
            'vehiculo_modelo' => $vehiculo->modelo,
            'vehiculo_placa' => $vehiculo->placa,
            'inspector_nombre' => 'Inspector Preop',
            'estado' => 'pendiente',
        ]);

        PreoperacionalDailyForm::create([
            'semana_id' => $semana->id,
            'dia_semana' => $diaSemana,
            'fecha' => $fecha->toDateString(),
            'completado' => $completado,
        ]);

        return $semana;
    }

    public function test_preop_hoy_counts_2359_bogota_forms_on_the_current_day(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 21, 23, 59, 0, 'America/Bogota'));
        Sanctum::actingAs($this->userWithAnalitica());

        $template = PreoperacionalTemplate::create([
            'codigo' => 'CAMION-001',
            'nombre' => 'Camion',
            'tipo_vehiculo' => 'camion',
            'activo' => true,
        ]);
        $fecha = Carbon::today();

        foreach (['001', '002', '003'] as $suffix) {
            $this->makePreopSemana(
                $this->makePreopVehicle($suffix),
                $template,
                $fecha,
                'martes',
                true,
            );
        }
        $this->makePreopVehicle('004');
        $this->makePreopVehicle('005');

        $response = $this->dashboardAll();

        $response->assertOk();
        $this->assertSame(5, $response->json('preopHoy.total'));
        $this->assertSame(3, $response->json('preopHoy.completados'));
        $this->assertSame(2, count($response->json('preopHoy.pendientes')));
        $this->assertSame(
            ['PRE004', 'PRE005'],
            collect($response->json('preopHoy.pendientes'))->pluck('placa')->sort()->values()->all(),
        );
        $this->assertSame('camion', $response->json('preopHoy.pendientes.0.tipo'));
    }

    public function test_preop_hoy_resets_to_the_new_bogota_day_at_0030(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 22, 0, 30, 0, 'America/Bogota'));
        Sanctum::actingAs($this->userWithAnalitica());

        $template = PreoperacionalTemplate::create([
            'codigo' => 'CAMION-002',
            'nombre' => 'Camion',
            'tipo_vehiculo' => 'camion',
            'activo' => true,
        ]);
        $vehiculo = $this->makePreopVehicle('006');
        $semanaInicio = Carbon::today()->copy()->startOfWeek(Carbon::MONDAY);
        $semana = PreoperacionalSemana::create([
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'template_id' => $template->id,
            'inspector_id' => $this->preopInspectorId(),
            'semana_inicio' => $semanaInicio->toDateString(),
            'semana_fin' => $semanaInicio->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            'semana_numero' => $semanaInicio->isoWeek,
            'semana_anio' => $semanaInicio->year,
            'vehiculo_marca' => $vehiculo->marca,
            'vehiculo_modelo' => $vehiculo->modelo,
            'vehiculo_placa' => $vehiculo->placa,
            'inspector_nombre' => 'Inspector Preop',
            'estado' => 'pendiente',
        ]);
        PreoperacionalDailyForm::create([
            'semana_id' => $semana->id,
            'dia_semana' => 'martes',
            'fecha' => '2026-07-21',
            'completado' => true,
        ]);
        PreoperacionalDailyForm::create([
            'semana_id' => $semana->id,
            'dia_semana' => 'miercoles',
            'fecha' => '2026-07-22',
            'completado' => false,
        ]);

        $response = $this->dashboardAll();

        $response->assertOk();
        $this->assertSame(1, $response->json('preopHoy.total'));
        $this->assertSame(0, $response->json('preopHoy.completados'));
        $this->assertSame([
            ['vehiculo_id' => $vehiculo->vehiculo_id, 'placa' => 'PRE006', 'tipo' => 'camion'],
        ], $response->json('preopHoy.pendientes'));
    }

    private function loanProduct(string $suffix): Producto
    {
        $categoria = Categoria::create([
            'categoria_nombre' => "Herramientas $suffix",
            'categoria_tipo' => 'herramienta',
        ]);

        return Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => "DASH-LOAN-$suffix",
            'producto_nombre' => "Herramienta $suffix",
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => 10,
            'producto_alerta_stock_minimo' => 1,
            'producto_precio_costo' => 5000,
        ]);
    }

    private function makeLoan(Producto $producto, int $mecanicoId, int $adminId, Carbon $fechaPrestamo): PrestamoHerramienta
    {
        return PrestamoHerramienta::create([
            'producto_id' => $producto->producto_id,
            'mecanico_id' => $mecanicoId,
            'admin_id' => $adminId,
            'prestamo_cantidad' => 1,
            'fecha_prestamo' => $fechaPrestamo,
            'estado' => 'prestado',
        ]);
    }

    public function test_loans_stats_marks_only_loans_older_than_seven_days_as_aged(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 21, 12, 0, 0, 'America/Bogota'));
        $user = $this->userWithAnalitica();
        Sanctum::actingAs($user);
        $mecanicoId = $this->mecanicoEmpleadoId();

        $this->makeLoan($this->loanProduct('SIX'), $mecanicoId, $user->id, Carbon::now()->subDays(6));
        $this->makeLoan($this->loanProduct('EIGHT'), $mecanicoId, $user->id, Carbon::now()->subDays(8));

        $response = $this->dashboardAll();

        $response->assertOk();
        $this->assertSame(2, $response->json('loansStats.activos'));
        $this->assertSame(1, $response->json('loansStats.envejecidos'));
        $this->assertSame('Herramienta EIGHT', $response->json('loansStats.items.0.producto.producto_nombre'));
        $this->assertSame('Test', $response->json('loansStats.items.0.mecanico.nombres'));
        $this->assertSame(8, $response->json('loansStats.items.0.dias'));
    }

    public function test_loans_stats_caps_aged_items_at_eight_oldest_first(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 21, 12, 0, 0, 'America/Bogota'));
        $user = $this->userWithAnalitica();
        Sanctum::actingAs($user);
        $mecanicoId = $this->mecanicoEmpleadoId();

        for ($days = 8; $days < 18; $days++) {
            $this->makeLoan(
                $this->loanProduct((string) $days),
                $mecanicoId,
                $user->id,
                Carbon::now()->subDays($days),
            );
        }

        $response = $this->dashboardAll();

        $response->assertOk();
        $this->assertSame(10, $response->json('loansStats.activos'));
        $this->assertSame(10, $response->json('loansStats.envejecidos'));
        $this->assertCount(8, $response->json('loansStats.items'));
        $this->assertSame('Herramienta 17', $response->json('loansStats.items.0.producto.producto_nombre'));
        $this->assertSame('Herramienta 10', $response->json('loansStats.items.7.producto.producto_nombre'));
    }

    private function lowStockProduct(string $suffix, float $stock, float $minimum): Producto
    {
        $categoria = Categoria::create([
            'categoria_nombre' => "Stock $suffix",
            'categoria_tipo' => 'repuesto',
        ]);

        return Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => "DASH-STOCK-$suffix",
            'producto_nombre' => "Stock $suffix",
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => $stock,
            'producto_alerta_stock_minimo' => $minimum,
            'producto_precio_costo' => 5000,
        ]);
    }

    public function test_low_stock_counts_twelve_qualifying_products_and_returns_eight_by_stock_ratio(): void
    {
        Sanctum::actingAs($this->userWithAnalitica());

        // Deliberately seed out of expected ratio order. SQLite stores these DECIMAL
        // fixture values as integers, so integer division would collapse most ratios
        // to zero and return this insertion order instead of the ratio order below.
        $products = [];
        foreach ([
            ['suffix' => 'FULL', 'stock' => 10, 'minimum' => 10],
            ['suffix' => 'HALF', 'stock' => 5, 'minimum' => 10],
            ['suffix' => 'THREE-QUARTERS', 'stock' => 6, 'minimum' => 8],
            ['suffix' => 'TIE-B', 'stock' => 2, 'minimum' => 10],
            ['suffix' => 'NINE-TENTHS', 'stock' => 9, 'minimum' => 10],
            ['suffix' => 'ZERO', 'stock' => 0, 'minimum' => 10],
            ['suffix' => 'QUARTER', 'stock' => 1, 'minimum' => 4],
            ['suffix' => 'TIE-A', 'stock' => 1, 'minimum' => 5],
            ['suffix' => 'EIGHT-TENTHS', 'stock' => 8, 'minimum' => 10],
            ['suffix' => 'HALF-OTHER', 'stock' => 3, 'minimum' => 6],
            ['suffix' => 'THIRD', 'stock' => 1, 'minimum' => 3],
            ['suffix' => 'SEVENTH', 'stock' => 1, 'minimum' => 7],
        ] as $product) {
            $products[$product['suffix']] = $this->lowStockProduct(
                $product['suffix'],
                $product['stock'],
                $product['minimum'],
            );
        }

        $response = $this->dashboardAll();

        $response->assertOk();
        $this->assertSame(12, $response->json('lowStock.count'));
        $this->assertCount(8, $response->json('lowStock.items'));
        $this->assertSame(
            [
                'Stock ZERO',
                'Stock SEVENTH',
                'Stock TIE-B',
                'Stock TIE-A',
                'Stock QUARTER',
                'Stock THIRD',
                'Stock HALF',
                'Stock HALF-OTHER',
            ],
            collect($response->json('lowStock.items'))->pluck('producto_nombre')->all(),
            'items must be ordered from the lowest stock/minimum ratio and capped at eight',
        );
        $this->assertSame(
            [
                $products['ZERO']->producto_id,
                $products['SEVENTH']->producto_id,
                $products['TIE-B']->producto_id,
                $products['TIE-A']->producto_id,
                $products['QUARTER']->producto_id,
                $products['THIRD']->producto_id,
                $products['HALF']->producto_id,
                $products['HALF-OTHER']->producto_id,
            ],
            collect($response->json('lowStock.items'))->pluck('producto_id')->all(),
            'equal ratios must use producto_id as the deterministic tie-breaker',
        );
    }

    public function test_low_stock_excludes_zero_or_negative_minimum_thresholds(): void
    {
        Sanctum::actingAs($this->userWithAnalitica());

        $included = $this->lowStockProduct('INCLUDED', 2, 5);
        $this->lowStockProduct('ZERO-MINIMUM', 0, 0);
        $this->lowStockProduct('NEGATIVE-MINIMUM', -1, -5);
        $this->lowStockProduct('ABOVE-MINIMUM', 6, 5);

        $response = $this->dashboardAll();

        $response->assertOk();
        $this->assertSame(1, $response->json('lowStock.count'));
        $this->assertSame([
            [
                'producto_id' => $included->producto_id,
                'producto_sku' => 'DASH-STOCK-INCLUDED',
                'producto_nombre' => 'Stock INCLUDED',
                'producto_unidad_medida' => 'unidad',
                'producto_stock_actual' => 2,
                'producto_alerta_stock_minimo' => 5,
            ],
        ], $response->json('lowStock.items'));
    }

    // --- DA-5.2 / DA-5.3 TDD: legacy document and kilometre alert parity ---

    private function alertVehicle(string $suffix, array $overrides = []): Vehiculo
    {
        return Vehiculo::create(array_merge([
            'placa' => "ALT$suffix",
            'tipo' => 'Camion',
            'categoria' => 'Carga',
            'tipo_combustible' => 'Diesel',
            'metodo_seguimiento' => 'kilometraje',
            'marca' => 'AlertMarca',
            'modelo' => 'AlertModelo',
            'kilometraje_actual' => 0,
        ], $overrides));
    }

    public function test_unified_alerts_keep_noon_date_only_scenarios_explicit(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 21, 12, 0, 0, 'America/Bogota'));
        Sanctum::actingAs($this->userWithAnalitica());

        $this->alertVehicle('SOAT20', [
            'fecha_vencimiento_soat' => Carbon::now()->addDays(20)->toDateString(),
        ]);
        $this->alertVehicle('SOATEX', [
            'fecha_vencimiento_soat' => Carbon::now()->subDays(3)->toDateString(),
        ]);
        $this->alertVehicle('SOAT31', [
            'fecha_vencimiento_soat' => Carbon::now()->addDays(31)->toDateString(),
        ]);
        $this->alertVehicle('KM499', [
            'kilometraje_actual' => 1_000,
            'kilometraje_proximo_mantenimiento' => 1_499,
        ]);
        $this->alertVehicle('KMEXP', [
            'kilometraje_actual' => 1_001,
            'kilometraje_proximo_mantenimiento' => 1_000,
        ]);

        $response = $this->dashboardAll();

        $response->assertOk();
        $this->assertSame(4, $response->json('alerts.total'));
        $this->assertSame(1, $response->json('alerts.counts.docs_vencidos'));
        $this->assertSame(1, $response->json('alerts.counts.docs_por_vencer'));
        $this->assertSame(2, $response->json('alerts.counts.servicios'));
        $this->assertSame([
            ['documento', 'soat', 'vencido', 'ALTSOATEX', 'Vencido 3d'],
            ['servicio', 'kilometraje', 'vencido', 'ALTKMEXP', '1 km venc.'],
            ['documento', 'soat', 'proximo', 'ALTSOAT20', '20d'],
            ['servicio', 'kilometraje', 'proximo', 'ALTKM499', '499 km'],
        ], collect($response->json('alerts.items'))
            ->map(fn (array $alert) => [
                $alert['tipo'],
                $alert['subtipo'],
                $alert['severity'],
                $alert['entidad']['label'],
                $alert['detalle'],
            ])
            ->all());
        $this->assertSame('fleet', $response->json('alerts.items.0.link.name'));
        $this->assertNull(collect($response->json('alerts.items'))->firstWhere('entidad.label', 'ALTSOAT31'));
    }

    public function test_unified_alerts_match_legacy_utc_midnight_date_only_semantics_after_1900_bogota(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 21, 20, 0, 0, 'America/Bogota'));
        Sanctum::actingAs($this->userWithAnalitica());

        $this->alertVehicle('UTC20', [
            'fecha_vencimiento_soat' => Carbon::now()->addDays(20)->toDateString(),
        ]);
        $this->alertVehicle('UTC31', [
            'fecha_vencimiento_soat' => Carbon::now()->addDays(31)->toDateString(),
        ]);
        $this->alertVehicle('UTCEX', [
            'fecha_vencimiento_soat' => Carbon::now()->subDays(3)->toDateString(),
        ]);
        $this->alertVehicle('UTCKM499', [
            'kilometraje_actual' => 1_000,
            'kilometraje_proximo_mantenimiento' => 1_499,
        ]);
        $this->alertVehicle('UTCKMEXP', [
            'kilometraje_actual' => 1_001,
            'kilometraje_proximo_mantenimiento' => 1_000,
        ]);

        $response = $this->dashboardAll();

        $response->assertOk();
        $this->assertSame(5, $response->json('alerts.total'));
        $this->assertSame(1, $response->json('alerts.counts.docs_vencidos'));
        $this->assertSame(2, $response->json('alerts.counts.docs_por_vencer'));
        $this->assertSame(2, $response->json('alerts.counts.servicios'));
        $this->assertSame([
            ['ALTUTCEX', 'Vencido 4d'],
            ['ALTUTCKMEXP', '1 km venc.'],
            ['ALTUTC20', '19d'],
            ['ALTUTC31', '30d'],
            ['ALTUTCKM499', '499 km'],
        ], collect($response->json('alerts.items'))
            ->map(fn (array $alert) => [$alert['entidad']['label'], $alert['detalle']])
            ->all());
    }

    public function test_unified_alerts_use_exclusive_tracking_axis_at_the_horometro_boundary(): void
    {
        Sanctum::actingAs($this->userWithAnalitica());

        $this->alertVehicle('HR50', [
            'metodo_seguimiento' => 'horometro',
            'horometro_actual' => 50,
            'horometro_proximo_mantenimiento' => 100,
        ]);
        $this->alertVehicle('HR51', [
            'metodo_seguimiento' => 'horometro',
            'horometro_actual' => 49,
            'horometro_proximo_mantenimiento' => 100,
        ]);
        $this->alertVehicle('HROTHERAXIS', [
            'metodo_seguimiento' => 'horometro',
            'horometro_actual' => 50,
            'horometro_proximo_mantenimiento' => 100,
            'kilometraje_actual' => 1_000,
            'kilometraje_proximo_mantenimiento' => 1_000,
        ]);
        $this->alertVehicle('KMOTHERAXIS', [
            'metodo_seguimiento' => 'kilometraje',
            'kilometraje_actual' => 1_000,
            'kilometraje_proximo_mantenimiento' => 1_500,
            'horometro_actual' => 100,
            'horometro_proximo_mantenimiento' => 100,
        ]);

        $response = $this->dashboardAll();

        $response->assertOk();
        $this->assertSame(3, $response->json('alerts.total'));
        $this->assertSame(3, $response->json('alerts.counts.servicios'));
        $this->assertSame([
            ['ALTHR50', 'horometro', '50 h'],
            ['ALTHROTHERAXIS', 'horometro', '50 h'],
            ['ALTKMOTHERAXIS', 'kilometraje', '500 km'],
        ], collect($response->json('alerts.items'))
            ->map(fn (array $alert) => [$alert['entidad']['label'], $alert['subtipo'], $alert['detalle']])
            ->all());
    }

    public function test_unified_alert_totals_remain_uncapped_while_items_are_unique_and_capped(): void
    {
        Sanctum::actingAs($this->userWithAnalitica());

        for ($index = 1; $index <= 10; $index++) {
            $this->lowStockProduct("UNCAPPED$index", 1, 10);
        }

        $response = $this->dashboardAll();
        $items = $response->json('alerts.items');
        $identities = collect($items)
            ->map(fn (array $alert) => implode(':', [
                $alert['tipo'],
                $alert['subtipo'],
                $alert['entidad']['kind'],
                $alert['entidad']['id'],
            ]));

        $response->assertOk();
        $this->assertSame(10, $response->json('alerts.total'));
        $this->assertSame(10, $response->json('alerts.counts.stock'));
        $this->assertCount(8, $items);
        $this->assertCount(8, $identities->unique());
    }

    public function test_unified_alerts_merge_horometro_stock_and_loans_with_counts_severity_and_cap(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 21, 12, 0, 0, 'America/Bogota'));
        $user = $this->userWithAnalitica();
        Sanctum::actingAs($user);

        foreach ([-1, -2, 20, 21] as $days) {
            $this->alertVehicle("DOC$days", [
                'fecha_vencimiento_soat' => Carbon::now()->addDays($days)->toDateString(),
            ]);
        }
        $this->alertVehicle('HREX', [
            'metodo_seguimiento' => 'horometro',
            'horometro_actual' => 101,
            'horometro_proximo_mantenimiento' => 100,
        ]);
        $this->alertVehicle('HR01', [
            'metodo_seguimiento' => 'horometro',
            'horometro_actual' => 99,
            'horometro_proximo_mantenimiento' => 100,
        ]);
        $this->lowStockProduct('CRITICAL', 2, 10);
        $this->lowStockProduct('SOON', 6, 10);
        $mecanicoId = $this->mecanicoEmpleadoId();
        $this->makeLoan($this->loanProduct('TEN'), $mecanicoId, $user->id, Carbon::now()->subDays(10));
        $this->makeLoan($this->loanProduct('THIRTY'), $mecanicoId, $user->id, Carbon::now()->subDays(30));

        $response = $this->dashboardAll();

        $response->assertOk();
        $this->assertSame(10, $response->json('alerts.total'));
        $this->assertSame([
            'docs_vencidos' => 2,
            'docs_por_vencer' => 2,
            'servicios' => 2,
            'stock' => 2,
            'prestamos' => 2,
        ], $response->json('alerts.counts'));
        $this->assertCount(8, $response->json('alerts.items'));
        $this->assertSame(
            ['vencido', 'vencido', 'vencido', 'vencido', 'vencido', 'proximo', 'proximo', 'proximo'],
            collect($response->json('alerts.items'))->pluck('severity')->all(),
        );
        $this->assertNotNull(collect($response->json('alerts.items'))->firstWhere('subtipo', 'horometro'));

        foreach ($response->json('alerts.items') as $alert) {
            $this->assertArrayHasKey('tipo', $alert);
            $this->assertArrayHasKey('subtipo', $alert);
            $this->assertArrayHasKey('severity', $alert);
            $this->assertArrayHasKey('entidad', $alert);
            $this->assertArrayHasKey('kind', $alert['entidad']);
            $this->assertArrayHasKey('id', $alert['entidad']);
            $this->assertArrayHasKey('label', $alert['entidad']);
            $this->assertArrayHasKey('detalle', $alert);
            $this->assertArrayHasKey('name', $alert['link']);
            $this->assertSame(
                match ($alert['tipo']) {
                    'documento', 'servicio' => 'fleet',
                    'stock' => 'inventory',
                    'prestamo' => 'loans',
                },
                $alert['link']['name'],
            );
        }
    }

    // --- DA-1.1 / WD-2 TDD: live sessions are bounded and relation-safe ---

    private function makeLiveSession(Carbon $startedAt, string $suffix): WorkSession
    {
        $empleadoId = $this->mecanicoEmpleadoId();
        $ordenTrabajo = $this->makeOt([
            'estado' => 'En Progreso',
            'mecanico_asignado_id' => $empleadoId,
            'descripcion' => "OT viva $suffix",
        ]);

        return WorkSession::create([
            'empleado_id' => $empleadoId,
            'orden_trabajo_id' => $ordenTrabajo->orden_trabajo_id,
            'fecha_inicio' => $startedAt,
            'fecha_fin' => null,
        ]);
    }

    public function test_live_sessions_caps_at_eight_eager_loads_employee_and_vehicle_and_calculates_elapsed_minutes(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 21, 12, 0, 0, 'America/Bogota'));
        Sanctum::actingAs($this->userWithAnalitica());

        for ($index = 1; $index <= 10; $index++) {
            $this->makeLiveSession(Carbon::now()->subMinutes($index * 5), (string) $index);
        }

        $response = $this->dashboardAll();

        $response->assertOk();
        $this->assertSame(10, $response->json('liveSessions.total'));
        $this->assertCount(8, $response->json('liveSessions.items'));
        $this->assertSame(5, $response->json('liveSessions.items.0.elapsed_min'));
        $this->assertSame('Test Mecanico', $response->json('liveSessions.items.0.empleado.nombre'));
        $this->assertSame('TES001', $response->json('liveSessions.items.0.ordenTrabajo.vehiculo.placa'));
        $this->assertSame('TES008', $response->json('liveSessions.items.7.ordenTrabajo.vehiculo.placa'));
    }

    // --- DA-4 / WD-3 TDD: three bounded source queries merge in Bogota order ---

    private function makeRecentActivityProduct(): Producto
    {
        return $this->lowStockProduct('ACTIVITY', 10, 1);
    }

    private function setCreatedAt(object $model, Carbon $at): void
    {
        $model->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    public function test_recent_activity_merges_three_sources_caps_at_eight_orders_newest_first_and_serializes_bogota(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 21, 12, 0, 0, 'America/Bogota'));
        $user = $this->userWithAnalitica();
        Sanctum::actingAs($user);
        $producto = $this->makeRecentActivityProduct();
        $vehiculo = $this->vehiculoForOt();
        $events = [];

        for ($index = 1; $index <= 8; $index++) {
            $at = Carbon::now()->subMinutes($index * 3);
            $ordenTrabajo = $this->makeOt([
                'vehiculo_id' => $vehiculo->vehiculo_id,
                'descripcion' => "OT actividad $index",
            ]);
            $this->setCreatedAt($ordenTrabajo, $at);
            $events[] = $at;

            $movimiento = TransaccionInventario::create([
                'producto_id' => $producto->producto_id,
                'usuario_id' => $user->id,
                'transaccion_tipo' => 'salida',
                'transaccion_cantidad' => $index,
                'transaccion_motivo' => 'consumo_ot',
            ]);
            $this->setCreatedAt($movimiento, $at->copy()->subMinute());
            $events[] = $at->copy()->subMinute();

            $tanqueo = RegistroCombustible::create([
                'vehiculo_id' => $vehiculo->vehiculo_id,
                'usuario_id' => $user->id,
                'fecha' => $at->copy()->subMinutes(2),
                'cantidad_galones' => $index,
                'valor_total' => $index * 10000,
                'tipo_combustible' => 'diesel',
            ]);
            $events[] = $tanqueo->fecha;
        }

        $response = $this->dashboardAll();
        $items = $response->json('recentActivity');

        $response->assertOk();
        $this->assertCount(8, $items);
        $this->assertSame(
            collect($items)->pluck('at')->sortDesc()->values()->all(),
            collect($items)->pluck('at')->values()->all(),
            'recent activity must be newest-first after the three source streams merge',
        );
        $this->assertSame('ot', $items[0]['type']);
        $this->assertStringEndsWith('-05:00', $items[0]['at']);
        $this->assertSame('OT actividad 1', $items[0]['titulo']);
        $this->assertContains('ot', collect($items)->pluck('type')->all());
        $this->assertContains('movimiento', collect($items)->pluck('type')->all());
    }

    public function test_recent_activity_keeps_events_older_than_a_day_without_a_time_window_filter(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 21, 12, 0, 0, 'America/Bogota'));
        Sanctum::actingAs($this->userWithAnalitica());
        $ordenTrabajo = $this->makeOt(['descripcion' => 'OT de la semana pasada']);
        $this->setCreatedAt($ordenTrabajo, Carbon::now()->subWeek());

        $response = $this->dashboardAll();

        $response->assertOk();
        $this->assertCount(1, $response->json('recentActivity'));
        $this->assertSame('ot', $response->json('recentActivity.0.type'));
        $this->assertStringStartsWith(Carbon::now()->subWeek()->toDateString(), $response->json('recentActivity.0.at'));
    }

    // --- DA-6.2 / DA-6.3: query discipline and real Sanctum endpoint proof ---

    private function dashboardQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->dashboardAll();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();

        return count($queries);
    }

    public function test_dashboard_alert_queries_are_bounded_when_vehicle_count_grows(): void
    {
        Sanctum::actingAs($this->userWithAnalitica());
        $this->alertVehicle('QUERY01', [
            'fecha_vencimiento_soat' => Carbon::now()->addDays(10)->toDateString(),
        ]);

        $oneVehicleQueries = $this->dashboardQueryCount();

        for ($index = 2; $index <= 20; $index++) {
            $this->alertVehicle(sprintf('QUERY%02d', $index), [
                'fecha_vencimiento_soat' => Carbon::now()->addDays(10)->toDateString(),
            ]);
        }

        $manyVehicleQueries = $this->dashboardQueryCount();

        $this->assertLessThanOrEqual(
            $oneVehicleQueries + 1,
            $manyVehicleQueries,
            'adding nineteen vehicles may add one bounded eager-load query, never per-vehicle queries',
        );
        $this->assertLessThanOrEqual(35, $manyVehicleQueries, 'dashboard BFF query count must remain bounded');
    }

    public function test_dashboard_uses_direct_eloquent_and_no_internal_work_order_http_proxy(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/AnalyticsApiController.php'));

        preg_match(
            '/public function getOtStats\(\): array(?<body>.*?)(?=public function getPreopHoy\(\): array)/s',
            $source,
            $matches,
        );
        $getOtStats = $matches['body'] ?? '';

        $this->assertNotSame('', $getOtStats, 'getOtStats method must be found for scoped proxy proof');
        $this->assertStringContainsString('OrdenTrabajo::query()', $getOtStats);
        $this->assertStringNotContainsString('/ordenes-trabajo', $getOtStats);
        $this->assertStringNotContainsString('Http::', $getOtStats);
    }

    public function test_authenticated_dashboard_runtime_contract_exposes_all_pr1_blocks(): void
    {
        Sanctum::actingAs($this->userWithAnalitica());

        $response = $this->dashboardAll();

        $response->assertOk()->assertJsonStructure([
            'summary',
            'fuelMonthly',
            'maintenanceByVehicle',
            'fuelStock',
            'fuelHistory15Days',
            'otStats',
            'preopHoy',
            'loansStats',
            'lowStock',
            'alerts' => ['total', 'counts', 'items'],
        ]);
    }
}
