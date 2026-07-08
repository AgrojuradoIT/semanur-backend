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
        // 1. preoperacional_templates
        Schema::create('preoperacional_templates', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->string('tipo_vehiculo');
            $table->text('descripcion')->nullable();
            $table->enum('escala_predeterminada', ['B_M', 'B_C_NC_M_N_A'])->default('B_M');
            $table->boolean('requiere_conductor')->default(false);
            $table->boolean('requiere_documentos_vehiculo')->default(false);
            $table->boolean('requiere_aprobacion')->default(false);
            $table->boolean('activo')->default(true);
            $table->integer('version')->default(1);
            $table->timestamps();

            $table->index(['tipo_vehiculo', 'activo']);
        });

        // 2. preoperacional_template_sections
        Schema::create('preoperacional_template_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('preoperacional_templates')->onDelete('cascade');
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->integer('orden')->default(0);
            $table->timestamps();
        });

        // 3. preoperacional_template_items
        Schema::create('preoperacional_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('preoperacional_templates')->onDelete('cascade');
            $table->foreignId('section_id')->nullable()->constrained('preoperacional_template_sections')->onDelete('set null');
            $table->string('codigo')->nullable();
            $table->text('pregunta');
            $table->enum('tipo_respuesta', ['escala', 'texto', 'numero', 'horometro'])->default('escala');
            $table->json('escala_valores')->nullable();
            $table->boolean('es_critico')->default(false);
            $table->boolean('requiere_observacion_si_falla')->default(false);
            $table->integer('orden')->default(0);
            $table->timestamps();

            $table->index(['template_id', 'orden']);
            $table->index(['section_id', 'orden']);
            $table->index(['template_id', 'es_critico']);
        });

        // 4. preoperacional_semanas
        Schema::create('preoperacional_semanas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehiculo_id');
            $table->foreignId('template_id')->constrained('preoperacional_templates')->onDelete('restrict');
            $table->foreignId('inspector_id')->constrained('empleados')->onDelete('restrict');
            $table->date('semana_inicio');
            $table->date('semana_fin');
            $table->tinyInteger('semana_numero');
            $table->smallInteger('semana_anio');
            $table->string('vehiculo_marca')->nullable();
            $table->string('vehiculo_modelo')->nullable();
            $table->string('vehiculo_placa');
            $table->json('conductor_snapshot')->nullable();
            $table->json('documentos_vehiculo_snapshot')->nullable();
            $table->boolean('fuera_de_servicio')->default(false);
            $table->text('motivo_fuera_servicio')->nullable();
            $table->text('observaciones_generales')->nullable();
            $table->enum('estado', ['pendiente', 'en_progreso', 'completado', 'fuera_servicio'])->default('pendiente');
            $table->string('inspector_nombre');
            $table->string('inspector_cargo')->nullable();
            $table->timestamps();

            $table->foreign('vehiculo_id')->references('vehiculo_id')->on('vehiculos')->onDelete('cascade');
            $table->unique(['vehiculo_id', 'template_id', 'semana_inicio'], 'preop_semanas_veh_tpl_inicio_uq');

            $table->index(['semana_anio', 'semana_numero']);
            $table->index('semana_inicio');
            $table->index(['vehiculo_id', 'semana_inicio']);
            $table->index('estado');
            $table->index('fuera_de_servicio');
            $table->index('inspector_id');
        });

        // 5. preoperacional_daily_forms
        Schema::create('preoperacional_daily_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semana_id')->constrained('preoperacional_semanas')->onDelete('cascade');
            $table->enum('dia_semana', ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo']);
            $table->date('fecha');
            $table->boolean('completado')->default(false);
            $table->text('observaciones_dia')->nullable();
            $table->timestamps();

            $table->unique(['semana_id', 'dia_semana']);

            $table->index('fecha');
            $table->index(['semana_id', 'completado']);
        });

        // 6. preoperacional_form_responses
        Schema::create('preoperacional_form_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_form_id')->constrained('preoperacional_daily_forms')->onDelete('cascade');
            $table->foreignId('item_id')->constrained('preoperacional_template_items')->onDelete('restrict');
            $table->enum('estado', ['B', 'M', 'C', 'NC', 'N', 'A']);
            $table->text('observacion')->nullable();
            $table->string('foto_url')->nullable();
            $table->timestamps();

            $table->unique(['daily_form_id', 'item_id']);

            $table->index('daily_form_id');
            $table->index(['item_id', 'estado']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preoperacional_form_responses');
        Schema::dropIfExists('preoperacional_daily_forms');
        Schema::dropIfExists('preoperacional_semanas');
        Schema::dropIfExists('preoperacional_template_items');
        Schema::dropIfExists('preoperacional_template_sections');
        Schema::dropIfExists('preoperacional_templates');
    }
};
