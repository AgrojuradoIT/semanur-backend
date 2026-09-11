<?php

namespace Tests\Feature\InventoryOrders\Concerns;

use App\Models\Bodega;
use App\Models\Categoria;
use App\Models\Empleado;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoRecepcion;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Str;

trait PedidoFixtures
{
    protected function usuario(string $role = 'contabilidad'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    protected function bodega(string $nombre = 'Bodega U1'): Bodega
    {
        return Bodega::create(['nombre' => $nombre, 'tipo' => 'estandar']);
    }

    protected function empleado(array $attributes = []): Empleado
    {
        return Empleado::create(array_merge([
            'nombres' => 'Receptor',
            'apellidos' => 'U1',
            'estado' => 'activo',
        ], $attributes));
    }

    protected function producto(): Producto
    {
        $categoria = Categoria::create([
            'categoria_nombre' => 'Categoría U1 '.Str::random(4),
            'categoria_tipo' => 'repuesto',
        ]);

        return Producto::create([
            'categoria_id' => $categoria->categoria_id,
            'producto_sku' => 'U1-'.Str::upper(Str::random(8)),
            'producto_nombre' => 'Producto U1',
        ]);
    }

    protected function pedido(?Bodega $bodega = null, ?User $creador = null): PedidoInventario
    {
        return PedidoInventario::create([
            'bodega_id' => ($bodega ?? $this->bodega())->bodega_id,
            'created_by' => ($creador ?? $this->usuario())->id,
            'proveedor_nombre_confirmado' => 'Proveedor U1',
        ]);
    }

    protected function linea(PedidoInventario $pedido, int $orden = 1, string $cantidad = '4.00'): PedidoInventarioLinea
    {
        return PedidoInventarioLinea::create([
            'pedido_id' => $pedido->pedido_id,
            'orden' => $orden,
            'sku_confirmado' => 'SKU-'.$orden,
            'cantidad_esperada' => $cantidad,
        ]);
    }

    protected function recepcion(PedidoInventario $pedido): PedidoRecepcion
    {
        return PedidoRecepcion::create(['pedido_id' => $pedido->pedido_id]);
    }
}
