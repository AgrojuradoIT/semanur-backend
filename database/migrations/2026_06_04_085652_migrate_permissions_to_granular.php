<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Migrar `role_permisos`
        $roles = DB::table('role_permisos')->get();
        foreach ($roles as $role) {
            $permisos = json_decode($role->permisos, true) ?? [];
            $newPermisos = [];
            foreach ($permisos as $p) {
                // Si el permiso ya tiene '.', lo dejamos (para que sea idempotente)
                if (str_contains($p, '.')) {
                    $newPermisos[] = $p;
                } else {
                    $newPermisos[] = "{$p}.read";
                    $newPermisos[] = "{$p}.write";
                }
            }
            DB::table('role_permisos')
                ->where('id', $role->id)
                ->update(['permisos' => json_encode(array_values(array_unique($newPermisos)))]);
        }

        // 2. Migrar `users`
        $users = DB::table('users')->whereNotNull('permisos')->get();
        foreach ($users as $user) {
            $permisos = json_decode($user->permisos, true) ?? [];
            $newPermisos = [];
            foreach ($permisos as $p) {
                if (str_contains($p, '.')) {
                    $newPermisos[] = $p;
                } else {
                    $newPermisos[] = "{$p}.read";
                    $newPermisos[] = "{$p}.write";
                }
            }
            DB::table('users')
                ->where('id', $user->id)
                ->update(['permisos' => json_encode(array_values(array_unique($newPermisos)))]);
        }

        // Limpiar cache de roles
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function down(): void
    {
        // Convertir de vuelta a "legacy"
        $roles = DB::table('role_permisos')->get();
        foreach ($roles as $role) {
            $permisos = json_decode($role->permisos, true) ?? [];
            $newPermisos = [];
            foreach ($permisos as $p) {
                $base = explode('.', $p)[0];
                $newPermisos[] = $base;
            }
            DB::table('role_permisos')
                ->where('id', $role->id)
                ->update(['permisos' => json_encode(array_values(array_unique($newPermisos)))]);
        }

        $users = DB::table('users')->whereNotNull('permisos')->get();
        foreach ($users as $user) {
            $permisos = json_decode($user->permisos, true) ?? [];
            $newPermisos = [];
            foreach ($permisos as $p) {
                $base = explode('.', $p)[0];
                $newPermisos[] = $base;
            }
            DB::table('users')
                ->where('id', $user->id)
                ->update(['permisos' => json_encode(array_values(array_unique($newPermisos)))]);
        }

        \Illuminate\Support\Facades\Cache::flush();
    }
};
