<?php

use App\Http\Middleware\CheckGranularPermission;
use App\Http\Middleware\CheckModulePermission;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureActiveEmployee;
use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// Cargar configuración de timezone ANTES de cualquier otra cosa
require_once __DIR__.'/../config/timezone.php';

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => CheckRole::class,
            'module' => CheckModulePermission::class,
            'permission' => CheckGranularPermission::class,
            'employee.active' => EnsureActiveEmployee::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, \Throwable $exception, Request $request): Response {
            if (! $request->is('api/*') || $response->getStatusCode() < 500) {
                return $response;
            }

            $errorId = (string) Str::uuid();
            Log::error('Unhandled API exception', [
                'error_id' => $errorId,
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $request->user()?->getKey(),
                'exception' => $exception,
            ]);

            return response()->json([
                'message' => 'No fue posible procesar la solicitud.',
                'error_id' => $errorId,
            ], $response->getStatusCode());
        });
    })
    ->withProviders([
        AdminPanelProvider::class,
    ])
    ->create();
