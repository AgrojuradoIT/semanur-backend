<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Capacidades nuevas del catálogo de pedidos asignadas a contabilidad.
     * No se incluyen pedidos.receive, pedidos.catalog.complete ni
     * pedidos.discrepancy.authorize (sección 7 del plan; D1 permanece cerrado).
     */
    private const CAPACIDADES_PEDIDOS = [
        'pedidos.read',
        'pedidos.create',
        'pedidos.document.write',
        'pedidos.publish',
        'pedidos.discrepancy.respond',
        'pedidos.cancel',
    ];

    public function up(): void
    {
        $jefeTaller = DB::table('role_permisos')->where('role', 'jefe_taller')->value('permisos');

        $base = $jefeTaller !== null ? json_decode((string) $jefeTaller, true) : null;
        if (! is_array($base)) {
            $base = [];
        }

        $permisos = array_values(array_unique(array_merge(
            array_values(array_filter($base, 'is_string')),
            self::CAPACIDADES_PEDIDOS,
        )));

        $now = now();

        DB::table('role_permisos')->updateOrInsert(
            ['role' => 'contabilidad'],
            [
                'permisos' => json_encode($permisos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        // User::roleDefaults() cachea por una hora la fila del rol.
        Cache::forget('role_permisos_contabilidad');
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: accounting role grants must be retained. Restore a verified backup for rollback.',
        );
    }
};
