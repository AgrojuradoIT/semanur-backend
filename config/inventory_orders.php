<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Feature flag
    |--------------------------------------------------------------------------
    |
    | The whole inventory-orders flow stays behind this flag until UAT and the
    | CSV cutover window are approved. Default is disabled.
    |
    */
    'enabled' => (bool) env('INVENTORY_ORDERS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | D1 authorization gate (discrepancy signing)
    |--------------------------------------------------------------------------
    |
    | D1 CONFIRMED (2026-09-10): the warehouse auxiliary signs the discrepancy.
    | The gate defaults to enabled with `auxiliar_bodega` as the only allowed
    | authorizer; both values remain env-overridable for rollback. Enabling the
    | flag is NOT enough: the role must appear in discrepancy_authorizer_roles,
    | the user needs the pedidos.discrepancy.authorize capability and an active
    | linked employee. Never use composer/user wildcards here.
    |
    */
    'discrepancy_authorization_enabled' => (bool) env('INVENTORY_ORDERS_DISCREPANCY_AUTHORIZATION_ENABLED', true),

    'discrepancy_authorizer_roles' => array_values(array_filter(array_map(
        fn (string $role): string => strtolower(trim($role)),
        explode(',', (string) env('INVENTORY_ORDERS_DISCREPANCY_AUTHORIZER_ROLES', 'auxiliar_bodega')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Close with zero stock (A = 0)
    |--------------------------------------------------------------------------
    |
    | CONSERVATIVE DEFAULT: false. Closing a discrepancy with zero ingress is a
    | pending business policy (see docs/inventory-orders/PENDIENTES.md), so by
    | default the novedad stays open. When true, an explicit A = 0 signature is
    | allowed and consumed with zero ledger movements (never a zero-quantity
    | transaction).
    |
    */
    'cierre_sin_ingreso_permitido' => (bool) env('INVENTORY_ORDERS_CIERRE_SIN_INGRESO_PERMITIDO', false),

    /*
    |--------------------------------------------------------------------------
    | Explicit capability opt-ins
    |--------------------------------------------------------------------------
    |
    | Each of these defaults to false. Admin does not inherit new reception or
    | supervisor capabilities implicitly; they must be granted on purpose.
    |
    */
    'admin_can_receive' => (bool) env('INVENTORY_ORDERS_ADMIN_CAN_RECEIVE', false),

    'supervisor_can_publish' => (bool) env('INVENTORY_ORDERS_SUPERVISOR_CAN_PUBLISH', false),

    'supervisor_can_cancel' => (bool) env('INVENTORY_ORDERS_SUPERVISOR_CAN_CANCEL', false),

    'supervisor_can_respond_discrepancy' => (bool) env('INVENTORY_ORDERS_SUPERVISOR_CAN_RESPOND_DISCREPANCY', false),

    /*
    |--------------------------------------------------------------------------
    | Private documents (U3)
    |--------------------------------------------------------------------------
    |
    | Evidence files live on a PRIVATE disk only ('local' root is
    | storage/app/private). Never point `disk` to `public`. Limits are
    | application-level guards validated before anything is stored; the
    | hosting PHP/proxy limits must be aligned at deploy time (see
    | docs/inventory-orders/U3-documentos.md).
    |
    */
    'documents' => [
        'disk' => (string) env('INVENTORY_ORDERS_DOCUMENTS_DISK', 'local'),
        'max_file_mb' => (float) env('INVENTORY_ORDERS_DOCUMENTS_MAX_FILE_MB', 10),
        'max_per_order' => (int) env('INVENTORY_ORDERS_DOCUMENTS_MAX_PER_ORDER', 20),
        'max_total_mb' => (float) env('INVENTORY_ORDERS_DOCUMENTS_MAX_TOTAL_MB', 100),
        'max_megapixels' => (float) env('INVENTORY_ORDERS_DOCUMENTS_MAX_MEGAPIXELS', 25),
        'orphan_ttl_hours' => (int) env('INVENTORY_ORDERS_DOCUMENTS_ORPHAN_TTL_HOURS', 24),
        'preview_jpeg_quality' => (int) env('INVENTORY_ORDERS_DOCUMENTS_PREVIEW_JPEG_QUALITY', 85),
    ],

    /*
    |--------------------------------------------------------------------------
    | OCR pipeline (U4)
    |--------------------------------------------------------------------------
    |
    | `provider` defaults to `fake` (deterministic fixtures, no network). The
    | real Google adapter (D2, U12) is implemented but OPT-IN: pointing to
    | `google` without `ocr.google.enabled=true` plus a readable server-side
    | credentials file fails closed with error_code proveedor_no_habilitado and
    | performs zero network attempts.
    |
    | `config_version` participates in the attempt work key: a reprocess or a
    | configuration change creates a new generation instead of overwriting a
    | previous result. `lease_seconds` must exceed `timeout`; it is enforced as
    | max(timeout + 30, lease_seconds).
    |
    | `google` block (U12): all values are server-side only. The service-account
    | JSON lives at `credentials_path`, which must be OUTSIDE public/ and the
    | repo, gitignored, and readable only by the app user (0600). It is never
    | logged, never included in exceptions or API responses. `project_id` is
    | informational (used only for redacted operational logs).
    |
    */
    'ocr' => [
        'provider' => (string) env('INVENTORY_ORDERS_OCR_PROVIDER', 'fake'),
        'config_version' => (string) env('INVENTORY_ORDERS_OCR_CONFIG_VERSION', 'v1'),
        'queue_connection' => env('INVENTORY_ORDERS_OCR_QUEUE_CONNECTION'),
        'queue' => (string) env('INVENTORY_ORDERS_OCR_QUEUE', 'default'),
        'timeout' => (int) env('INVENTORY_ORDERS_OCR_TIMEOUT', 60),
        'max_intentos' => (int) env('INVENTORY_ORDERS_OCR_MAX_INTENTOS', 3),
        'backoff_base_seconds' => (int) env('INVENTORY_ORDERS_OCR_BACKOFF_BASE_SECONDS', 10),
        'backoff_max_seconds' => (int) env('INVENTORY_ORDERS_OCR_BACKOFF_MAX_SECONDS', 300),
        'lease_seconds' => (int) env('INVENTORY_ORDERS_OCR_LEASE_SECONDS', 120),
        'fake_fixtures_path' => env('INVENTORY_ORDERS_OCR_FAKE_FIXTURES_PATH', base_path('tests/fixtures/ocr')),
        'google' => [
            'enabled' => (bool) env('INVENTORY_ORDERS_OCR_GOOGLE_ENABLED', false),
            'project_id' => env('GOOGLE_CLOUD_PROJECT'),
            'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),
            'timeout' => (int) env('INVENTORY_ORDERS_OCR_GOOGLE_TIMEOUT', 60),
            'endpoint' => (string) env(
                'INVENTORY_ORDERS_OCR_GOOGLE_ENDPOINT',
                'https://vision.googleapis.com/v1/images:annotate'
            ),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Realtime opt-in (U11)
    |--------------------------------------------------------------------------
    |
    | Delivery of `pedido_alertas` over private broadcast channels is OPT-IN and
    | defaults to OFF everywhere. The persisted inbox (`pedido_alertas` + its
    | polling API) remains the working path and the source of truth; realtime
    | only accelerates the UI.
    |
    | Enabling this flag is NOT enough by itself: the operator must also set a
    | real BROADCAST_CONNECTION (Reverb/Pusher) and keep a broadcast server
    | running. Cero FCM ever for these events, and the legacy Notificacion
    | pipeline, its counters and the `user.{id}` channel are never touched.
    |
    */
    'realtime' => [
        'enabled' => (bool) env('INVENTORY_ORDERS_REALTIME_ENABLED', false),
    ],
];
