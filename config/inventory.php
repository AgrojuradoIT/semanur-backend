<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default warehouse
    |--------------------------------------------------------------------------
    |
    | Optional explicit fallback for server-side inventory operations. When it
    | is not configured, callers must provide bodega_id.
    |
    */
    'default_bodega_id' => env('INVENTORY_DEFAULT_BODEGA_ID'),

    /*
    | Enable only after every supported client sends a stable UUID on the
    | first attempt and reuses it for retries. During rollout, supplied UUIDs
    | are still fully protected while legacy clients remain compatible.
    */
    'require_client_operation_id' => (bool) env('INVENTORY_REQUIRE_CLIENT_OPERATION_ID', false),

    'imports' => [
        'max_file_kilobytes' => (int) env('INVENTORY_IMPORT_MAX_FILE_KB', 5120),
        'max_rows' => (int) env('INVENTORY_IMPORT_MAX_ROWS', 5000),
        'max_uncompressed_bytes' => (int) env('INVENTORY_IMPORT_MAX_UNCOMPRESSED_BYTES', 33554432),
        'max_zip_entries' => (int) env('INVENTORY_IMPORT_MAX_ZIP_ENTRIES', 256),
        'header_scan_rows' => (int) env('INVENTORY_IMPORT_HEADER_SCAN_ROWS', 100),
        'max_columns' => (int) env('INVENTORY_IMPORT_MAX_COLUMNS', 64),
    ],
];
