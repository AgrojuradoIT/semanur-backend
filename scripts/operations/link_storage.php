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

$link = $root.'/public/storage';
$force = in_array('--force', $argv, true);

if ($force && is_link($link)) {
    unlink($link);
}

if ($force && is_dir($link)) {
    $entries = array_diff(scandir($link) ?: [], ['.', '..']);
    if ($entries !== []) {
        fwrite(STDERR, "Refusing to delete a non-empty public/storage directory.\n");
        exit(2);
    }
    rmdir($link);
}

Artisan::call('storage:link');
echo trim(Artisan::output())."\n";
