<?php

namespace Tests\Feature\InventoryOrders;

use App\Models\PedidoNovedad;
use App\Models\TransaccionInventario;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\InventoryOrders\Concerns\PedidoDesfaseFixtures;
use Tests\TestCase;

// U9 — monitor de integridad de solo lectura (plan sección 16): verifica el
// contrato ledger<->pedido sin reparar nada. Fixtures SQLite por HTTP real;
// las inconsistencias se inyectan por DB directa (fuera del dominio).
class CheckInventoryOrdersIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use PedidoDesfaseFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inventory_orders.enabled' => true]);
        $this->configurarD1();
    }

    public function test_clean_conforme_reception_reports_ok(): void
    {
        $producto = $this->productoConSku('SKU-INT-OK');
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-INT-OK', 'overrides' => ['producto_id' => $producto->producto_id]],
        ]);
        $linea = $this->lineaDe($pedido, 1);

        $this->contar($receptor, $pedido, (int) $recepcion['recepcion_id'], $linea, [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'conforme',
        ])->assertOk();
        $this->confirmar($receptor, $pedido, (int) $recepcion['recepcion_id'])->assertOk();

        $this->artisan('inventory-orders:check-integrity')
            ->expectsOutputToContain('Integridad OK')
            ->assertExitCode(Command::SUCCESS);

        // El monitor no muta: el conteo de movimientos no cambia por correrlo.
        $this->assertDatabaseCount('transaccion_inventarios', 1);
    }

    public function test_mixed_reception_with_open_novedad_reports_ok(): void
    {
        $this->pedidoConNovedadAbierta();

        $this->artisan('inventory-orders:check-integrity')
            ->expectsOutputToContain('Integridad OK')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_desfase_closed_reception_reports_ok(): void
    {
        [$pedido, $receptor, $lineaNovedad, $novedad] = $this->pedidoConNovedadAbierta();

        $this->autorizar($receptor, $pedido, (int) $novedad->novedad_id)->assertOk();
        $segunda = $this->abrirRecepcion($receptor, $pedido)->assertStatus(201);
        $segundaId = (int) $segunda->json('data.recepcion_id');
        $this->confirmar($receptor, $pedido, $segundaId)->assertOk();

        $this->assertSame(
            'recibida_con_desfase',
            $lineaNovedad->fresh()->estado,
        );

        $this->artisan('inventory-orders:check-integrity')
            ->expectsOutputToContain('Integridad OK')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_missing_ledger_row_for_ingested_line_is_a_finding(): void
    {
        $producto = $this->productoConSku('SKU-INT-DUP');
        [$pedido, $receptor, $recepcion] = $this->pedidoEnRecepcion([
            ['sku' => 'SKU-INT-DUP', 'overrides' => ['producto_id' => $producto->producto_id]],
        ]);
        $linea = $this->lineaDe($pedido, 1);

        $this->contar($receptor, $pedido, (int) $recepcion['recepcion_id'], $linea, [
            'conteo_total' => '4.00',
            'conteo_aptos' => '4.00',
            'estado_condicion' => 'conforme',
        ])->assertOk();
        $this->confirmar($receptor, $pedido, (int) $recepcion['recepcion_id'])->assertOk();

        // Falla simulada: el ingreso quedó sin movimiento (proyección
        // perdida). El caso duplicado no es inyectable: el unique real de
        // pedido_linea_id lo impide incluso por DB directa.
        TransaccionInventario::query()
            ->where('pedido_linea_id', $linea->linea_id)
            ->delete();

        $this->artisan('inventory-orders:check-integrity', ['--format' => 'json'])
            ->assertExitCode(Command::FAILURE);

        $this->artisan('inventory-orders:check-integrity', ['--format' => 'json'])
            ->expectsOutputToContain('LEDGER_COUNT_MISMATCH');
    }

    public function test_ledger_row_on_blocked_line_is_a_finding(): void
    {
        [$pedido, , $lineaNovedad] = $this->pedidoConNovedadAbierta();

        TransaccionInventario::query()->forceCreate([
            'producto_id' => $lineaNovedad->producto_id,
            'bodega_id' => $pedido->bodega_id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => '1.00',
            'transaccion_motivo' => 'Fila fantasma (fixture de integridad)',
            'transaccion_referencia_type' => 'pedido_inventario',
            'transaccion_referencia_id' => $pedido->pedido_id,
            'pedido_linea_id' => $lineaNovedad->linea_id,
        ]);

        $this->artisan('inventory-orders:check-integrity', ['--format' => 'json'])
            ->expectsOutputToContain('BLOCKED_LINE_WITH_LEDGER')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_stale_open_novedad_is_reported(): void
    {
        $this->pedidoConNovedadAbierta();

        DB::table('pedido_novedades')
            ->where('estado', 'abierta')
            ->update(['abierta_at' => now()->subDays(10)]);

        $this->artisan('inventory-orders:check-integrity')
            ->expectsOutputToContain('NOVEDAD_ABIERTA_STALE')
            ->assertExitCode(Command::FAILURE);

        // Bajo el umbral explícito no se marca estancada.
        $this->artisan('inventory-orders:check-integrity', ['--stale-days' => 30])
            ->expectsOutputToContain('Integridad OK')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_json_output_carries_summary_counts(): void
    {
        $this->pedidoConNovedadAbierta();

        // Artisan::call directo: el mock de $this->artisan() solo satisface
        // una subcadena por línea emitida (Mockery despacha a la primera
        // expectativa coincidente).
        $this->assertSame(0, Artisan::call('inventory-orders:check-integrity', ['--format' => 'json']));

        $salida = Artisan::output();
        $this->assertStringContainsString('"clean":true', $salida);
        $this->assertStringContainsString('"lineas_revisadas":2', $salida);
        $this->assertStringContainsString('"novedades_abiertas":1', $salida);
        $this->assertSame(2, json_decode($salida, true, 512, JSON_THROW_ON_ERROR)['summary']['lineas_revisadas']);
    }

    public function test_rejects_unknown_format_without_touching_data(): void
    {
        $this->pedidoConNovedadAbierta();
        $movimientos = TransaccionInventario::query()->count();

        $this->artisan('inventory-orders:check-integrity', ['--format' => 'xml'])
            ->assertExitCode(Command::INVALID);

        $this->assertSame($movimientos, TransaccionInventario::query()->count());
        $this->assertSame(1, PedidoNovedad::query()->where('estado', 'abierta')->count());
    }
}
