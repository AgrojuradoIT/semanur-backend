<?php
// Test de routing Laravel
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "<h2>Test de Rutas</h2>";
echo "<pre>";

// Probar si la app responde
echo "App: " . get_class($app) . "\n";
echo "Laravel: " . app()->version() . "\n";

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
    $request = Illuminate\Http\Request::create('/panel/login', 'GET');
    $response = $kernel->handle($request);
    echo "Status panel/login: " . $response->status() . "\n";
} catch (Exception $e) {
    echo "Error panel/login: " . $e->getMessage() . "\n";
}

echo "</pre>";
