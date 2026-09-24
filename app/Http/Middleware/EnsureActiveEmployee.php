<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveEmployee
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->isOperationallyActive()) {
            return $next($request);
        }

        $token = $user->currentAccessToken();
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        $user->forceFill([
            'fcm_token' => null,
            'fcm_token_updated_at' => null,
        ])->saveQuietly();

        return response()->json([
            'message' => 'La cuenta está deshabilitada por estado laboral.',
        ], 403);
    }
}
