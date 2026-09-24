<?php

namespace Tests\Feature\InventoryOrders;

use App\Domain\InventoryOrders\PedidoEstado;
use App\Models\PedidoInventario;
use App\Models\User;
use App\Policies\PedidoInventarioPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\InventoryOrders\Concerns\PedidoFixtures;
use Tests\TestCase;

class PedidoInventarioPolicyTest extends TestCase
{
    use PedidoFixtures, RefreshDatabase;

    private PedidoInventarioPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new PedidoInventarioPolicy();
    }

    public function test_read_is_available_to_operational_roles_only(): void
    {
        $pedido = $this->pedido();

        foreach (['admin', 'contabilidad', 'auxiliar_bodega', 'jefe_taller'] as $role) {
            $this->assertTrue(
                $this->policy->viewAny($this->usuario($role)),
                "viewAny debía permitir el rol {$role}",
            );
            $this->assertTrue(
                $this->policy->view($this->usuario($role), $pedido),
                "view debía permitir el rol {$role}",
            );
        }

        $this->assertFalse($this->policy->viewAny($this->usuario('operativo')));
        $this->assertFalse($this->policy->view($this->usuario('visualizador'), $pedido));
    }

    public function test_create_and_publish_are_accounting_by_default(): void
    {
        $pedido = $this->pedido();

        $this->assertTrue($this->policy->create($this->usuario('contabilidad')));
        $this->assertTrue($this->policy->create($this->usuario('admin')));

        $this->assertFalse($this->policy->create($this->usuario('auxiliar_bodega')));
        $this->assertFalse($this->policy->create($this->usuario('operativo')));
        $this->assertFalse(
            $this->policy->create($this->usuario('jefe_taller')),
            'El jefe de taller no hereda create/publish sin habilitación explícita.',
        );

        $this->assertTrue($this->policy->publish($this->usuario('contabilidad'), $pedido));
        $this->assertFalse($this->policy->publish($this->usuario('auxiliar_bodega'), $pedido));
    }

    public function test_publish_requires_draft_state(): void
    {
        $pedido = $this->pedido();
        $contabilidad = $this->usuario('contabilidad');

        $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION);

        $this->assertFalse($this->policy->publish($contabilidad, $pedido->fresh()));
    }

    public function test_receive_requires_warehouse_role_and_explicit_admin_opt_in(): void
    {
        $pedido = $this->pedido();
        $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION);
        $pedido = $pedido->fresh();

        $this->assertTrue($this->policy->receive($this->usuario('auxiliar_bodega'), $pedido));
        $this->assertTrue($this->policy->completeCatalog($this->usuario('auxiliar_bodega'), $pedido));

        $this->assertFalse($this->policy->receive($this->usuario('contabilidad'), $pedido));
        $this->assertFalse($this->policy->receive($this->usuario('jefe_taller'), $pedido));
        $this->assertFalse(
            $this->policy->receive($this->usuario('admin'), $pedido),
            'Admin no recibe por defecto: requiere opt-in explícito.',
        );

        config(['inventory_orders.admin_can_receive' => true]);

        $this->assertTrue($this->policy->receive($this->usuario('admin'), $pedido));

        $pending = $this->pedido();
        $this->assertFalse(
            $this->policy->receive($this->usuario('auxiliar_bodega'), $pending),
            'No se recibe mientras el pedido siga en borrador.',
        );
    }

    public function test_discrepancy_authorization_default_is_enabled_and_env_disabled_blocks(): void
    {
        $pedido = $this->pedido();
        $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION);
        $pedido->transicionarA(PedidoEstado::EN_VALIDACION);
        $pedido = $pedido->fresh();

        // D1 confirmado (2026-09-10): habilitado por defecto solo para la
        // auxiliar de bodega; admin y contabilidad no firman.
        $this->assertTrue((bool) config('inventory_orders.discrepancy_authorization_enabled'));
        $this->assertSame(['auxiliar_bodega'], config('inventory_orders.discrepancy_authorizer_roles'));

        $this->assertTrue($this->policy->authorizeDiscrepancy($this->usuario('auxiliar_bodega'), $pedido));
        $this->assertFalse($this->policy->authorizeDiscrepancy($this->usuario('admin'), $pedido));
        $this->assertFalse($this->policy->authorizeDiscrepancy($this->usuario('contabilidad'), $pedido));

        // Override de entorno que apaga D1: nadie firma.
        config([
            'inventory_orders.discrepancy_authorization_enabled' => false,
            'inventory_orders.discrepancy_authorizer_roles' => [],
        ]);

        foreach (['admin', 'contabilidad', 'auxiliar_bodega', 'jefe_taller'] as $role) {
            $this->assertFalse(
                $this->policy->authorizeDiscrepancy($this->usuario($role), $pedido),
                "D1 apagado no debía permitir firmar a {$role}.",
            );
        }

        config([
            'inventory_orders.discrepancy_authorization_enabled' => true,
            'inventory_orders.discrepancy_authorizer_roles' => [],
        ]);

        $this->assertFalse(
            $this->policy->authorizeDiscrepancy($this->usuario('admin'), $pedido),
            'Con lista de autorizadores vacía nadie firma, ni siquiera admin.',
        );

        config([
            'inventory_orders.discrepancy_authorization_enabled' => true,
            'inventory_orders.discrepancy_authorizer_roles' => ['auxiliar_bodega'],
        ]);

        $this->assertTrue($this->policy->authorizeDiscrepancy($this->usuario('auxiliar_bodega'), $pedido));
        $this->assertFalse($this->policy->authorizeDiscrepancy($this->usuario('admin'), $pedido));
    }

    public function test_cancel_is_blocked_after_any_ledger_ingress(): void
    {
        $pedido = $this->pedido();
        $linea = $this->linea($pedido);
        $contabilidad = $this->usuario('contabilidad');

        $this->assertTrue($this->policy->cancel($contabilidad, $pedido));

        $producto = $this->producto();

        DB::table('transaccion_inventarios')->insert([
            'producto_id' => $producto->producto_id,
            'transaccion_tipo' => 'ingreso',
            'transaccion_cantidad' => '4.00',
            'transaccion_motivo' => 'compra',
            'pedido_linea_id' => $linea->linea_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(
            $this->policy->cancel($contabilidad, $pedido->fresh()),
            'Con ledger emitido ya no se cancela.',
        );

        $recepcion = $this->recepcion($pedido);
        $recepcion->transicionarA(\App\Domain\InventoryOrders\RecepcionEstado::CONFIRMADA);

        $this->assertFalse($this->policy->cancel($contabilidad, $pedido->fresh()));
    }

    public function test_update_is_limited_to_draft_and_delete_is_always_denied(): void
    {
        $pedido = $this->pedido();
        $contabilidad = $this->usuario('contabilidad');

        $this->assertTrue($this->policy->update($contabilidad, $pedido));

        $pedido->transicionarA(PedidoEstado::PENDIENTE_RECEPCION);
        $pedido = $pedido->fresh();

        $this->assertFalse($this->policy->update($contabilidad, $pedido));

        foreach (['admin', 'contabilidad', 'auxiliar_bodega'] as $role) {
            $this->assertFalse($this->policy->delete($this->usuario($role), $pedido));
            $this->assertFalse($this->policy->restore($this->usuario($role), $pedido));
            $this->assertFalse($this->policy->forceDelete($this->usuario($role), $pedido));
        }
    }

    public function test_capability_catalog_is_complete(): void
    {
        $expected = [
            'pedidos.read',
            'pedidos.create',
            'pedidos.document.write',
            'pedidos.publish',
            'pedidos.receive',
            'pedidos.catalog.complete',
            'pedidos.discrepancy.respond',
            'pedidos.discrepancy.authorize',
            'pedidos.cancel',
        ];

        $this->assertSame($expected, PedidoInventarioPolicy::capabilities());
    }
}
