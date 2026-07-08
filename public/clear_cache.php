<?php

// Script para limpiar caché en hosting compartido (sin SSH)
// Sube este archivo a la carpeta 'public' o 'public_html' y ejecútalo cuando necesites limpiar caché:
// http://backsm.agrojurado.com/clear_cache.php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

use Illuminate\Support\Facades\Artisan;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

try {
    echo "<h2>Limpiando cachés...</h2>";
    
    Artisan::call('cache:clear');
    echo "1. Cache: " . Artisan::output() . "<br>";
    
    Artisan::call('config:clear');
    echo "2. Config: " . Artisan::output() . "<br>";
    
    Artisan::call('route:clear');
    echo "3. Rutas: " . Artisan::output() . "<br>";
    
    Artisan::call('view:clear');
    echo "4. Vistas: " . Artisan::output() . "<br>";

    // Re-cachear configuración para producción
    Artisan::call('config:cache');
    echo "5. Config Cache (Re-generado): " . Artisan::output() . "<br>";
    
    echo "<h3>¡Proceso terminado exitosamente!</h3>";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
