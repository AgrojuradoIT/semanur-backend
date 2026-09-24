<?php

namespace Tests\Feature\InventoryOrders;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidoContabilidadParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Derechos legacy equivalentes otorgados a nivel de usuario para aislar la
     * paridad de rol: la matriz debe medir middleware de rol y permitir que
     * ambos roles lleguen al controlador por los mismos motivos.
     */
    private const PERMISOS_HEREDADOS = [
        'combustible.write',
        'inventario.write',
        'taller.write',
        'prestamos.write',
        'movimientos.write',
        'analitica.read',
    ];

    public function test_contabilidad_matches_jefe_taller_on_representative_legacy_endpoints(): void
    {
        $jefe = $this->actor('jefe_taller');
        $contabilidad = $this->actor('contabilidad');

        $casos = [
            'POST /api/combustible' => ['POST', '/api/combustible', []],
            'POST /api/productos' => ['POST', '/api/productos', []],
            'POST /api/inventario/import-compras' => ['POST', '/api/inventario/import-compras', []],
            'POST /api/ordenes-trabajo/{id}/repuestos' => ['POST', '/api/ordenes-trabajo/999999/repuestos', ['repuestos' => []]],
            'POST /api/prestamos' => ['POST', '/api/prestamos', []],
            'POST /api/movimientos' => ['POST', '/api/movimientos', []],
        ];

        foreach ($casos as $nombre => [$method, $url, $payload]) {
            $estadoJefe = $this->probe($jefe, $method, $url, $payload);
            $estadoContabilidad = $this->probe($contabilidad, $method, $url, $payload);

            $this->assertSame(
                $estadoJefe,
                $estadoContabilidad,
                "Paridad rota en {$nombre}: jefe_taller={$estadoJefe}, contabilidad={$estadoContabilidad}",
            );
            $this->assertNotSame(
                403,
                $estadoJefe,
                "jefe_taller quedó bloqueado en {$nombre}; la paridad no probaría acceso real",
            );
        }
    }

    public function test_admin_only_routes_stay_denied_for_both_roles(): void
    {
        $jefe = $this->actor('jefe_taller');
        $contabilidad = $this->actor('contabilidad');

        $casos = [
            'GET /api/history/all' => ['GET', '/api/history/all', []],
            'DELETE /api/combustible/{id}' => ['DELETE', '/api/combustible/1', []],
            'PUT /api/productos/{id}' => ['PUT', '/api/productos/1', []],
        ];

        foreach ($casos as $nombre => [$method, $url, $payload]) {
            $this->assertSame(
                403,
                $this->probe($jefe, $method, $url, $payload),
                "jefe_taller debía quedar fuera de {$nombre}",
            );
            $this->assertSame(
                403,
                $this->probe($contabilidad, $method, $url, $payload),
                "contabilidad debía quedar fuera de {$nombre}",
            );
        }
    }

    private function actor(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'permisos' => self::PERMISOS_HEREDADOS,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function probe(User $user, string $method, string $url, array $payload): int
    {
        Sanctum::actingAs($user);

        return $this->json($method, $url, $payload)->getStatusCode();
    }
}
