<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo "Semanur deployment preparation (CLI only)\n";

foreach (['optimize:clear', 'filament:upgrade', 'config:cache'] as $command) {
    echo "Running {$command}... ";
    Artisan::call($command);
    echo "OK\n";
}

echo "No database command was executed. Apply the reviewed production SQL separately.\n";
