<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$v = App\Models\Vehiculo::where('placa', 'KJA431')->first(['vehiculo_id','placa','imagen_url','imagen_thumb_url']);
echo json_encode($v, JSON_PRETTY_PRINT) . PHP_EOL;

// Also check if files exist
if ($v && $v->imagen_url) {
    $path = __DIR__ . '/storage/app/public/' . $v->imagen_url;
    echo "File exists: " . (file_exists($path) ? 'YES' : 'NO') . " ($path)" . PHP_EOL;
}
if ($v && $v->imagen_thumb_url) {
    $path = __DIR__ . '/storage/app/public/' . $v->imagen_thumb_url;
    echo "Thumb exists: " . (file_exists($path) ? 'YES' : 'NO') . " ($path)" . PHP_EOL;
}
