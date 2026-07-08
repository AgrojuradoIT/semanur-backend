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
        Schema::create('prestamos_herramientas', function (Blueprint $table) {
            $table->id('prestamo_id');
            $table->foreignId('producto_id')->constrained('productos', 'producto_id')->cascadeOnDelete();
            $table->foreignId('mecanico_id')->constrained('users', 'id')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('users', 'id')->cascadeOnDelete();
            $table->decimal('prestamo_cantidad', 10, 2);
            $table->dateTime('fecha_prestamo');
            $table->dateTime('fecha_devolucion')->nullable();
            $table->string('estado')->default('prestado'); // prestado, devuelto, dañado, perdido
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prestamos_herramientas');
    }
};
