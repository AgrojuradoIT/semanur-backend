<?php

/**
 * Deploy Semanur — Laravel 13 + Filament 5 + Hosting Compartido
 * 
 * Subir a: public_html/backsm.agrojurado.com/
 * Ejecutar: https://backsm.agrojurado.com/deploy.php
 * ELIMINAR después de ejecutar por seguridad.
 */

echo "<h2>Semanur Deploy</h2>";
echo "<pre>";

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. Limpiar cachés
echo "1. Limpiando caché... ";
Artisan::call('optimize:clear');
echo "OK\n";

// 2. Publicar assets Filament
echo "2. Assets Filament 5... ";
Artisan::call('filament:upgrade');
echo "OK\n";

// 3. Migraciones
echo "3. Migraciones... ";
try {
    Artisan::call('migrate', ['--force' => true]);
    echo trim(Artisan::output()) . "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// 4. Verificar estructura
echo "4. Verificando...\n";
echo "   Laravel: " . app()->version() . " | PHP: " . PHP_VERSION . "\n";

$htaccessRoot = file_exists(__DIR__.'/../.htaccess');
echo "   .htaccess raíz: " . ($htaccessRoot ? 'OK' : 'FALTA - Subir backend/.htaccess') . "\n";

// Verificar acceso directo a panel/login
echo "5. Ruta /panel/login: ";
try {
    $routes = Route::getRoutes();
    $found = false;
    foreach ($routes as $route) {
        if ($route->uri() === 'panel/login') {
            $found = true;
            break;
        }
    }
    echo $found ? "OK\n" : "FALTA\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n✅ Deploy completado. " . date('Y-m-d H:i:s') . "\n";
echo "</pre>";

echo "<p>Accedé a: <a href='/panel/login'>/panel/login</a></p>";
echo "<p style='color:red'><strong>IMPORTANTE:</strong> Eliminá deploy.php del hosting.</p>";
