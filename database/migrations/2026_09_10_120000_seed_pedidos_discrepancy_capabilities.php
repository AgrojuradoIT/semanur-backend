<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Capacidades U7 de novedades y firma de desfase:
     * - auxiliar_bodega responde y firma desfases (D1 confirmado: firma la
     *   auxiliar de bodega, reutilizando su cuenta operativa);
     * - la lectura ya vino de U5 y `pedidos.discrepancy.respond` de contabilidad
     *   de U2: no se tocan esas filas.
     *
     * Aditiva e idempotente: fusiona sin quitar permisos existentes.
     */
    private const CAPACIDADES_POR_ROL = [
        'auxiliar_bodega' => [
            'pedidos.discrepancy.respond',
            'pedidos.discrepancy.authorize',
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
            'Forward-only migration: discrepancy capability grants must be retained. Restore a verified backup for rollback.',
        );
    }
};
