<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckGranularPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        if ($user->isAdmin()) {
            return $next($request);
        }

        if (empty($permissions)) {
            return $next($request);
        }

        if (!$user->canAnyPermission($permissions)) {
            $nombres = implode(', ', $permissions);
            return response()->json([
                'message' => "No tienes el permiso requerido. Necesitas uno de: {$nombres}.",
            ], 403);
        }

        return $next($request);
    }
}
