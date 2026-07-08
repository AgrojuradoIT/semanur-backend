<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckModulePermission
{
    public function handle(Request $request, Closure $next, string ...$modulos): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        // Admin siempre tiene acceso total
        if ($user->isAdmin()) {
            return $next($request);
        }

        // Si no se especifican módulos, se permite el acceso
        if (empty($modulos)) {
            return $next($request);
        }

        // Verificar si el usuario tiene acceso a al menos uno de los módulos
        if (!$user->canAccessAnyModule($modulos)) {
            $nombres = collect($modulos)
                ->map(fn ($m) => \App\Models\User::modulos()[$m] ?? $m)
                ->join(', ');

            return response()->json([
                'message' => "No tienes permiso para acceder a: {$nombres}.",
            ], 403);
        }

        return $next($request);
    }
}
