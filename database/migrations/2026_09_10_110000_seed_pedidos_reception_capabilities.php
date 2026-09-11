<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Capacidades U5 requeridas por la puerta de publicación (U5):
     * - auxiliar_bodega recibe y completa catálogo, además de lectura;
     * - jefe_taller obtiene lectura para supervisión.
     *
     * Se fusiona con los permisos existentes sin quitarlos; no se otorga
     * receive/catalog.complete a jefe_taller ni por equivalencia heredada.
     */
    private const CAPACIDADES_POR_ROL = [
        'auxiliar_bodega' => [
            'pedidos.read',
            'pedidos.receive',
            'pedidos.catalog.complete',
        ],
        'jefe_taller' => [
            'pedidos.read',
        ],
    ];

    public function up(): void
    {
        foreach (self::CAPACIDADES_POR_ROL as $role => $capacidades) {
            $raw = DB::table('role_permisos')->where('role', $role)->value('permisos');
            $base = $raw !== null ? json_decode((string) $raw, true) : null;

            if (! is_array($base)) {
                $base = [];
            }

            $permisos = array_values(array_unique(array_merge(
                array_values(array_filter($base, 'is_string')),
                $capacidades,
            )));

            DB::table('role_permisos')->updateOrInsert(
                ['role' => $role],
                [
                    'permisos' => json_encode($permisos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            // User::roleDefaults() cachea la fila del rol por una hora.
            Cache::forget("role_permisos_{$role}");
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only migration: reception capability grants must be retained. Restore a verified backup for rollback.',
        );
    }
};
