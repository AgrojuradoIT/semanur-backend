<?php

namespace Tests\Feature\Api;

use App\Models\Categoria;
use App\Models\Empleado;
use App\Models\ItemListaChequeo;
use App\Models\ListaChequeo;
use App\Models\Producto;
use App\Models\TransaccionInventario;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UatBusinessFlowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_uat_work_order_session_and_closure_flow(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@semanur.com']);
        $mecanicoUser = User::factory()->create(['role' => 'mecanico', 'permisos' => ['taller']]);
        $mecanicoEmpleado = $this->crearEmpleado($mecanicoUser->id, 'Mecanico Uno', 'Mecanico');
        $vehiculo = $this->crearVehiculo('UAT101');

        $categoria = Categoria::create([
            'categoria_nombre' => 'Repuestos',
            'categoria_tipo' => 'repuesto',
        ]);

        $repuesto = $this->crearProducto($categoria->categoria_id, 'SKU-UAT-RP', 'Filtro aceite', 10);
        $herramienta = $this->crearProducto($categoria->categoria_id, 'SKU-UAT-HR', 'Llave mixta', 5);

        Sanctum::actingAs($admin);
        $crearOt = $this->postJson('/api/ordenes-trabajo', [
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'mecanico_asignado_id' => $mecanicoEmpleado->id,
            'prioridad' => 'Alta',
            'descripcion' => 'Cambio general de filtros',
            'repuestos' => [
                ['producto_id' => $repuesto->producto_id, 'cantidad' => 2],
            ],
            'herramientas' => [
                ['producto_id' => $herramienta->producto_id, 'cantidad' => 1],
            ],
        ]);

        $crearOt->assertCreated();
        $ordenId = (int) $crearOt->json('orden.orden_trabajo_id');
        $this->assertGreaterThan(0, $ordenId);

        $this->assertSame(8.0, (float) $repuesto->fresh()->producto_stock_actual);
        $this->assertSame(4.0, (float) $herramienta->fresh()->producto_stock_actual);

        $this->assertDatabaseHas('transaccion_inventarios', [
            'producto_id' => $repuesto->producto_id,
            'transaccion_tipo' => 'salida',
            'transaccion_referencia_type' => 'OrdenTrabajo',
            'transaccion_referencia_id' => $ordenId,
        ]);
        $this->assertDatabaseHas('transaccion_inventarios', [
            'producto_id' => $herramienta->producto_id,
            'transaccion_tipo' => 'salida',
            'transaccion_referencia_type' => 'PrestamoHerramienta',
        ]);

        Sanctum::actingAs($mecanicoUser);
        $start = $this->postJson('/api/sesiones-trabajo/start', [
            'orden_trabajo_id' => $ordenId,
        ]);
        $start->assertCreated();
        $sessionId = (int) $start->json('session.sesion_id');

        $this->postJson('/api/sesiones-trabajo/' . $sessionId . '/stop', [
            'notas' => 'Orden ejecutada',
        ])->assertOk();

        $this->patchJson('/api/ordenes-trabajo/' . $ordenId . '/estado', [
            'estado' => 'Cerrada',
        ])->assertOk();

        $show = $this->getJson('/api/ordenes-trabajo/' . $ordenId);
        $show->assertOk();
        $show->assertJsonPath('estado', 'Cerrada');
    }

    public function test_uat_internal_fuel_dispatch_to_employee(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@semanur.com']);
        $operadorUser = User::factory()->create(['role' => 'operador']);
        $operadorEmpleado = $this->crearEmpleado($operadorUser->id, 'Operador Uno', 'Operador');

        $categoria = Categoria::create([
            'categoria_nombre' => 'Combustible',
            'categoria_tipo' => 'combustible',
        ]);
        $diesel = $this->crearProducto($categoria->categoria_id, 'SKU-UAT-DIESEL', 'Diesel ACPM', 100);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/combustible', [
            'tipo_destino' => 'empleado',
            'empleado_id' => $operadorEmpleado->id,
            'tipo_combustible' => 'acpm',
            'tercero_nombre' => 'Operador Uno',
            'cantidad_galones' => 10,
            'valor_total' => 120000,
            'estacion_servicio' => 'Tanque principal',
            'producto_id' => $diesel->producto_id,
            'notas' => 'Despacho UAT',
        ]);

        $response->assertCreated();
        $this->assertSame(90.0, (float) $diesel->fresh()->producto_stock_actual);

        $transaccion = TransaccionInventario::query()
            ->where('producto_id', $diesel->producto_id)
            ->where('transaccion_tipo', 'salida')
            ->latest('transaccion_id')
            ->first();

        $this->assertNotNull($transaccion);
        $this->assertSame('EmpleadoTexto', $transaccion->transaccion_referencia_type);
        $this->assertNull($transaccion->transaccion_referencia_id);
    }

    public function test_uat_loan_return_and_checklist_with_employee_ids(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@semanur.com']);
        $mecanicoUser = User::factory()->create(['role' => 'mecanico']);
        $mecanicoEmpleado = $this->crearEmpleado($mecanicoUser->id, 'Mecanico Dos', 'Mecanico');
        $operadorUser = User::factory()->create(['role' => 'operador']);
        $operadorEmpleado = $this->crearEmpleado($operadorUser->id, 'Operador Dos', 'Operador');

        $categoria = Categoria::create([
            'categoria_nombre' => 'Herramientas',
            'categoria_tipo' => 'herramienta',
        ]);
        $producto = $this->crearProducto($categoria->categoria_id, 'SKU-UAT-LOAN', 'Taladro', 6);
        $vehiculo = $this->crearVehiculo('UAT202');
        [$lista, $itemCritico] = $this->crearListaYItem('tractor');

        Sanctum::actingAs($admin);
        $prestamo = $this->postJson('/api/prestamos', [
            'producto_id' => $producto->producto_id,
            'mecanico_id' => $mecanicoEmpleado->id,
            'prestamo_cantidad' => 2,
            'notas' => 'Prestamo UAT',
        ]);
        $prestamo->assertOk();
        $prestamoId = (int) $prestamo->json('prestamo.prestamo_id');
        $this->assertSame(4.0, (float) $producto->fresh()->producto_stock_actual);

        $this->postJson('/api/prestamos/' . $prestamoId . '/devolver', [
            'estado' => 'devuelto',
            'notas' => 'Devuelto en buen estado',
        ])->assertOk();
        $this->assertSame(6.0, (float) $producto->fresh()->producto_stock_actual);

        $check = $this->postJson('/api/checklists', [
            'lista_chequeo_id' => $lista->id,
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'operador_id' => $operadorEmpleado->id,
            'respuestas' => [
                (string) $itemCritico->id => 'falla',
            ],
            'observaciones_generales' => 'Falla en frenos',
        ]);
        $check->assertCreated();
        $check->assertJsonPath('estado_final', 'rechazado');

        $history = $this->getJson('/api/checklists/history?vehiculo_id=' . $vehiculo->vehiculo_id);
        $history->assertOk();
        $this->assertNotEmpty($history->json('data'));
    }

    private function crearEmpleado(int $userId, string $nombreCompleto, string $cargo): Empleado
    {
        $partes = explode(' ', $nombreCompleto, 2);

        return Empleado::create([
            'nombres' => $partes[0],
            'apellidos' => $partes[1] ?? '',
            'user_id' => $userId,
            'cargo' => $cargo,
        ]);
    }

    private function crearVehiculo(string $placa): Vehiculo
    {
        return Vehiculo::create([
            'placa' => $placa,
            'tipo' => 'tractor',
            'marca' => 'CAT',
            'modelo' => 'D6',
        ]);
    }

    private function crearProducto(int $categoriaId, string $sku, string $nombre, float $stock): Producto
    {
        return Producto::create([
            'categoria_id' => $categoriaId,
            'producto_sku' => $sku,
            'producto_nombre' => $nombre,
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => $stock,
            'producto_alerta_stock_minimo' => 1,
            'producto_precio_costo' => 1000,
        ]);
    }

    private function crearListaYItem(string $tipoVehiculo): array
    {
        $lista = ListaChequeo::create([
            'nombre' => 'Checklist UAT',
            'tipo_vehiculo' => $tipoVehiculo,
            'activo' => true,
        ]);

        $item = ItemListaChequeo::create([
            'lista_chequeo_id' => $lista->id,
            'pregunta' => 'Sistema de frenos',
            'tipo_respuesta' => 'cumple_falla',
            'orden' => 1,
            'es_critico' => true,
        ]);

        return [$lista, $item];
    }
}

