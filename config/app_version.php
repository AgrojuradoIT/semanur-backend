<?php

/**
 * Configuración de versiones de la app móvil Semanur HUB.
 *
 * Para publicar una nueva versión:
 * 1. Subir el APK a storage/app/public/apks/
 * 2. Actualizar latest_version y download_url
 * 3. Opcional: agregar release_notes
 * 4. Si es crítica: force_update = true
 */

return [
    // Última versión disponible
    'latest' => env('APP_VERSION_LATEST', '1.0.0'),

    // Versión mínima requerida (usuarios con versión anterior deben actualizar)
    'min' => env('APP_VERSION_MIN', '1.0.0'),

    // URL pública para descargar el APK
    'download_url' => env('APP_VERSION_DOWNLOAD_URL', ''),

    // Notas de la versión (se muestran en el dialog de actualización)
    'release_notes' => env('APP_VERSION_RELEASE_NOTES', ''),

    // Si es true, el usuario no puede saltar la actualización
    'force_update' => env('APP_VERSION_FORCE_UPDATE', false),
];
