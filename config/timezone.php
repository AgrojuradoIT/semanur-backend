<?php

/**
 * Configuración de Timezone para la aplicación
 * 
 * Esta configuración asegura que todas las fechas se manejen en la zona horaria de Bogotá (UTC-5)
 */

use Carbon\Carbon;

// Establecer timezone por defecto para PHP
date_default_timezone_set('America/Bogota');

// Establecer timezone para Carbon/Laravel (no es estático, se usa la instancia por defecto)
Carbon::setLocale('es_CO');

// Configurar formato de fecha por defecto para serialización
Carbon::serializeUsing(function ($date) {
    return $date->setTimezone('America/Bogota')->toIso8601String();
});
