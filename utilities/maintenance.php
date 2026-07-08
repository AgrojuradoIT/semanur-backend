#!/usr/bin/env php
<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Script de utilidades para mantenimiento del backend
 * 
 * Uso:
 *   php utilities/maintenance.php
 *   php utilities/maintenance.php --clear-cache
 *   php utilities/maintenance.php --check-timezone
 */

define('LARAVEL_START', microtime(true));

// Argumentos de línea de comandos
$clearCache = in_array('--clear-cache', $argv);
$checkTimezone = in_array('--check-timezone', $argv);
$help = in_array('--help', $argv) || in_array('-h', $argv);

if ($help) {
    echo "Uso: php utilities/maintenance.php [opciones]\n\n";
    echo "Opciones:\n";
    echo "  --clear-cache      Limpiar cache de Laravel\n";
    echo "  --check-timezone   Verificar configuración de timezone\n";
    echo "  --help, -h         Mostrar esta ayuda\n";
    exit(0);
}

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "==============================================\n";
echo "  Mantenimiento del Backend\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "==============================================\n\n";

// Limpiar cache
if ($clearCache) {
    echo "🧹 Limpiando cache...\n\n";
    
    echo "📁 Configuración...\n";
    Artisan::call('config:clear');
    echo Artisan::output() . "\n";
    
    echo "🗑️  Cache general...\n";
    Artisan::call('cache:clear');
    echo Artisan::output() . "\n";
    
    echo "🛣️  Rutas...\n";
    Artisan::call('route:clear');
    echo Artisan::output() . "\n";
    
    echo "🎨 Vistas...\n";
    Artisan::call('view:clear');
    echo Artisan::output() . "\n";
    
    echo "✅ Cache limpiado exitosamente\n\n";
}

// Verificar timezone
if ($checkTimezone || !$clearCache) {
    echo "🕐 Verificando Timezone...\n\n";
    
    $phpTimezone = date_default_timezone_get();
    $carbonNow = Carbon::now();
    $carbonTimezone = $carbonNow->tzName;
    $carbonOffset = $carbonNow->offsetHours;
    $carbonISO = $carbonNow->toIso8601String();
    
    echo "  PHP Timezone:     $phpTimezone\n";
    echo "  Carbon Timezone:  $carbonTimezone\n";
    echo "  Offset:           {$carbonOffset} horas\n";
    echo "  Hora actual:      $carbonISO\n\n";
    
    if ($phpTimezone === 'America/Bogota' && $carbonOffset == -5) {
        echo "  ✅ Timezone configurada correctamente para Bogotá (UTC-5)\n\n";
    } else {
        echo "  ⚠️  Timezone NO es Bogotá. Verifica config/timezone.php\n\n";
    }
}

// Mostrar resumen
echo "==============================================\n";
echo "  Resumen\n";
echo "==============================================\n";
echo "  Tiempo de ejecución: " . round((microtime(true) - LARAVEL_START), 2) . " segundos\n";
echo "  PHP Version: " . PHP_VERSION . "\n";
echo "  Laravel Version: " . app()->version() . "\n";
echo "==============================================\n";
