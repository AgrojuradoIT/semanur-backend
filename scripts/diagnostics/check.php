<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
// Test de routing Laravel
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require_once $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo '<h2>Test de Rutas</h2>';
echo '<pre>';

// Probar si la app responde
echo 'App: '.get_class($app)."\n";
echo 'Laravel: '.app()->version()."\n";

// Verificar rutas
$routes = Route::getRoutes();
$panelRoutes = 0;
foreach ($routes as $route) {
    if (str_starts_with($route->uri(), 'panel')) {
        $panelRoutes++;
    }
}
echo "Rutas /panel: {$panelRoutes}\n";

// Probar acceso a panel/login directamente
try {
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $request = Request::create('/panel/login', 'GET');
    $response = $kernel->handle($request);
    echo 'Status panel/login: '.$response->status()."\n";
} catch (Exception $e) {
    echo 'Error panel/login: '.$e->getMessage()."\n";
}

echo '</pre>';
