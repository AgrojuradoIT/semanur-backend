<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInventoryOrdersEnabled
{
    /**
     * Oculta por completo la superficie de pedidos de inventario cuando el
     * feature flag está apagado. El 404 evita revelar que la función existe y
     * se aplica antes de auth en el grupo de rutas. No requiere alias global.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('inventory_orders.enabled', false)) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        }

        return $next($request);
    }
}
