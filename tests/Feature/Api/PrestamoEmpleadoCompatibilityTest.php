<?php

namespace Tests\Feature\Api;

use App\Models\Categoria;
use App\Models\Empleado;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrestamoEmpleadoCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_saves_mecanico_empleado_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@semanur.com']);
        $mecanicoUser = User::factory()->create(['role' => 'mecanico']);
        $mecanicoEmpleado = $this->crearEmpleado($mecanicoUser->id, 'Mecanico Uno');
        $producto = $this->crearProducto(10);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/prestamos', [
            'producto_id' => $producto->producto_id,
            'mecanico_id' => $mecanicoEmpleado->id,
            'prestamo_cantidad' => 2,
            'notas' => 'Prestamo de prueba',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('prestamos_herramientas', [
            'producto_id' => $producto->producto_id,
            'mecanico_id' => $mecanicoEmpleado->id,
            'admin_id' => $admin->id,
        ]);
        $this->assertSame(8.0, (float) $producto->fresh()->producto_stock_actual);
    }

    public function test_store_rejects_invalid_mecanico_reference(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@semanur.com']);
        $producto = $this->crearProducto(10);

        Sanctum::actingAs($admin);

        $this->postJson('/api/prestamos', [
            'producto_id' => $producto->producto_id,
            'mecanico_id' => 999999,
            'prestamo_cantidad' => 2,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mecanico_id']);
    }

    private function crearEmpleado(int $userId, string $nombreCompleto): Empleado
    {
        $partes = explode(' ', $nombreCompleto, 2);

        return Empleado::create([
            'nombres' => $partes[0],
            'apellidos' => $partes[1] ?? '',
            'user_id' => $userId,
            'cargo' => 'Mecanico',
        ]);
    }

    private function crearProducto(float $stockInicial): Producto
    {
        $categoria = Categoria::create([
            'categoria_nombre' => 'Herramientas',
            'categoria_tipo' => 'herramienta',
        ]);

        return Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'SKU-' . uniqid(),
            'producto_nombre' => 'Llave inglesa',
            'producto_unidad_medida' => 'unidad',
            'producto_stock_actual' => $stockInicial,
            'producto_alerta_stock_minimo' => 1,
            'producto_precio_costo' => 5000,
        ]);
    }
}

