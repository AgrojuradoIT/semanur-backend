<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $userRole = $user->role ?? 'visualizador';

        if (!in_array($userRole, $roles)) {
            return response()->json([
                'message' => 'No tienes permisos para esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
