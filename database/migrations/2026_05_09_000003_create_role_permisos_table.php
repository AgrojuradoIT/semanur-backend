<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permisos', function (Blueprint $table) {
            $table->id();
            $table->string('role')->unique();
            $table->json('permisos');
            $table->timestamps();
        });

        // Seed default permissions
        $defaults = [
            'jefe_taller' => ['taller', 'flota', 'personal', 'inventario', 'prestamos', 'checklists', 'combustible', 'analitica'],
            'auxiliar_bodega' => ['inventario', 'movimientos', 'prestamos', 'combustible', 'checklists'],
            'operativo' => ['checklists', 'combustible'],
            'visualizador' => ['analitica'],
        ];

        foreach ($defaults as $role => $permisos) {
            DB::table('role_permisos')->insert([
                'role' => $role,
                'permisos' => json_encode($permisos),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permisos');
    }
};
