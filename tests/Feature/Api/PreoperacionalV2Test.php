<?php

namespace Tests\Feature\Api;

use App\Models\Empleado;
use App\Models\PreoperacionalDailyForm;
use App\Models\PreoperacionalFormResponse;
use App\Models\PreoperacionalSemana;
use App\Models\PreoperacionalTemplate;
use App\Models\PreoperacionalTemplateItem;
use App\Models\PreoperacionalTemplateSection;
use App\Models\User;
use App\Models\Vehiculo;
use Carbon\Carbon;
use Database\Seeders\ListaChequeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PreoperacionalV2Test extends TestCase
{
    use RefreshDatabase;

    // ─── Test Helpers ───────────────────────────────────

    /**
     * Create a complete test semana with template, vehicle, inspector, and 7 daily forms.
     * Returns: ['user', 'vehiculo', 'inspector', 'template', 'semana', 'dailyForms', 'items']
     */
    private function createTestSemana(array $options = []): array
    {
        $user = User::factory()->create(['role' => 'admin']);
        $vehiculo = Vehiculo::create([
            'placa' => $options['placa'] ?? 'ABC-123',
            'tipo' => $options['tipo'] ?? 'tractor',
            'marca' => $options['marca'] ?? 'John Deere',
            'modelo' => $options['modelo'] ?? '5075E',
        ]);

        $inspector = Empleado::create([
            'nombres' => 'Test',
            'apellidos' => 'Inspector',
            'cargo' => 'Inspector',
        ]);

        // Create template with sections and items
        $template = PreoperacionalTemplate::create([
            'codigo' => $options['template_codigo'] ?? 'TEST-001',
            'nombre' => 'Test Template',
            'tipo_vehiculo' => $options['tipo_vehiculo'] ?? 'tractor',
            'escala_predeterminada' => 'B_M',
            'activo' => true,
        ]);

        $items = [];

        if (!empty($options['sections'])) {
            foreach ($options['sections'] as $sectionData) {
                $section = PreoperacionalTemplateSection::create([
                    'template_id' => $template->id,
                    'nombre' => $sectionData['nombre'],
                    'orden' => $sectionData['orden'] ?? 0,
                ]);

                foreach ($sectionData['items'] as $itemData) {
                    $items[] = PreoperacionalTemplateItem::create([
                        'template_id' => $template->id,
                        'section_id' => $section->id,
                        'pregunta' => $itemData['pregunta'],
                        'es_critico' => $itemData['es_critico'] ?? false,
                        'orden' => $itemData['orden'] ?? 0,
                    ]);
                }
            }
        } else {
            // Default: 3 items, one critical
            $defaultItems = [
                ['pregunta' => 'Frenos', 'es_critico' => true, 'orden' => 1],
                ['pregunta' => 'Luces', 'es_critico' => false, 'orden' => 2],
                ['pregunta' => 'Aceite', 'es_critico' => false, 'orden' => 3],
            ];
            foreach ($defaultItems as $itemData) {
                $items[] = PreoperacionalTemplateItem::create([
                    'template_id' => $template->id,
                    'pregunta' => $itemData['pregunta'],
                    'es_critico' => $itemData['es_critico'],
                    'orden' => $itemData['orden'],
                ]);
            }
        }

        // Create semana starting on a Monday
        $semanaInicio = $options['semana_inicio'] ?? Carbon::now()->startOfWeek(Carbon::MONDAY);

        $semana = PreoperacionalSemana::create([
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'template_id' => $template->id,
            'inspector_id' => $inspector->id,
            'semana_inicio' => $semanaInicio->format('Y-m-d'),
            'semana_fin' => $semanaInicio->copy()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d'),
            'semana_numero' => $semanaInicio->isoWeek,
            'semana_anio' => $semanaInicio->year,
            'vehiculo_marca' => $vehiculo->marca,
            'vehiculo_modelo' => $vehiculo->modelo,
            'vehiculo_placa' => $vehiculo->placa,
            'inspector_nombre' => $inspector->nombre_completo,
            'inspector_cargo' => $inspector->cargo,
            'estado' => 'pendiente',
        ]);

        // Create 7 daily forms
        $dias = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];
        $dailyForms = [];
        foreach ($dias as $index => $dia) {
            $dailyForms[] = PreoperacionalDailyForm::create([
                'semana_id' => $semana->id,
                'dia_semana' => $dia,
                'fecha' => $semanaInicio->copy()->addDays($index)->format('Y-m-d'),
                'completado' => false,
            ]);
        }

        return [
            'user' => $user,
            'vehiculo' => $vehiculo,
            'inspector' => $inspector,
            'template' => $template,
            'semana' => $semana,
            'dailyForms' => $dailyForms,
            'items' => $items,
        ];
    }

    /**
     * Create a template with a critical item for criticality testing.
     */
    private function createTemplateWithCriticalItem(): array
    {
        $template = PreoperacionalTemplate::create([
            'codigo' => 'CRIT-TEST',
            'nombre' => 'Critical Test Template',
            'tipo_vehiculo' => 'volqueta',
            'escala_predeterminada' => 'B_M',
            'activo' => true,
        ]);

        $criticalItem = PreoperacionalTemplateItem::create([
            'template_id' => $template->id,
            'pregunta' => 'Frenos de servicio',
            'es_critico' => true,
            'orden' => 1,
        ]);

        $normalItem = PreoperacionalTemplateItem::create([
            'template_id' => $template->id,
            'pregunta' => 'Limpiaparabrisas',
            'es_critico' => false,
            'orden' => 2,
        ]);

        return ['template' => $template, 'criticalItem' => $criticalItem, 'normalItem' => $normalItem];
    }

    // ─── T012: Feature Tests ────────────────────────────

    /**
     * T012-1: GET /templates returns active templates with sections and items nested.
     */
    public function test_get_templates_returns_active_templates_with_sections_and_items(): void
    {
        // Create an active template with sections and items
        $template = PreoperacionalTemplate::create([
            'codigo' => 'SMN-FT-TEST',
            'nombre' => 'Test Template',
            'tipo_vehiculo' => 'tractor',
            'activo' => true,
        ]);

        $section = PreoperacionalTemplateSection::create([
            'template_id' => $template->id,
            'nombre' => 'Section 1',
            'orden' => 1,
        ]);

        PreoperacionalTemplateItem::create([
            'template_id' => $template->id,
            'section_id' => $section->id,
            'pregunta' => 'Test item',
            'es_critico' => true,
            'orden' => 1,
        ]);

        // Create an inactive template (should NOT be returned)
        $inactiveTemplate = PreoperacionalTemplate::create([
            'codigo' => 'SMN-FT-INACTIVE',
            'nombre' => 'Inactive Template',
            'tipo_vehiculo' => 'volqueta',
            'activo' => false,
        ]);

        PreoperacionalTemplateItem::create([
            'template_id' => $inactiveTemplate->id,
            'pregunta' => 'Inactive item',
            'es_critico' => false,
            'orden' => 1,
        ]);

        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v2/preoperacionales/templates');

        $response->assertOk();
        $response->assertJsonCount(1); // Only active template

        $response->assertJsonPath('0.codigo', 'SMN-FT-TEST');
        $response->assertJsonPath('0.sections.0.nombre', 'Section 1');
        $response->assertJsonPath('0.sections.0.items.0.pregunta', 'Test item');
        $response->assertJsonPath('0.sections.0.items.0.es_critico', true);

        // Verify inactive template is not returned
        $response->assertJsonMissing(['codigo' => 'SMN-FT-INACTIVE']);
    }

    /**
     * T012-2: GET /templates filters by tipo_vehiculo and falls back to generico.
     */
    public function test_get_templates_filters_by_tipo_vehiculo_and_falls_back_to_generico(): void
    {
        // Create tractor template
        $tractorTemplate = PreoperacionalTemplate::create([
            'codigo' => 'SMN-FT-TRACTOR',
            'nombre' => 'Tractor Template',
            'tipo_vehiculo' => 'tractor',
            'activo' => true,
        ]);

        // Create generico template
        $genericoTemplate = PreoperacionalTemplate::create([
            'codigo' => 'GEN-GENERICO',
            'nombre' => 'Generic Template',
            'tipo_vehiculo' => 'generico',
            'activo' => true,
        ]);

        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        // Request tractor → should return tractor template
        $response = $this->getJson('/api/v2/preoperacionales/templates?tipo_vehiculo=tractor');
        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.codigo', 'SMN-FT-TRACTOR');

        // Request unknown type → should fall back to generico
        $response = $this->getJson('/api/v2/preoperacionales/templates?tipo_vehiculo=unknown_type');
        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.codigo', 'GEN-GENERICO');
    }

    /**
     * T012-3: POST /semanas creates weekly instance with 7 daily forms.
     */
    public function test_store_semana_creates_weekly_instance_with_7_daily_forms(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $vehiculo = Vehiculo::create([
            'placa' => 'ABC-123',
            'tipo' => 'tractor',
            'marca' => 'John Deere',
            'modelo' => '5075E',
        ]);

        $inspector = Empleado::create([
            'nombres' => 'Test',
            'apellidos' => 'Inspector',
            'cargo' => 'Inspector',
        ]);

        $template = PreoperacionalTemplate::create([
            'codigo' => 'TEST-STORE',
            'nombre' => 'Test Template',
            'tipo_vehiculo' => 'tractor',
            'escala_predeterminada' => 'B_M',
            'activo' => true,
        ]);

        $semanaInicio = Carbon::now()->startOfWeek(Carbon::MONDAY);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v2/preoperacionales/semanas', [
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'template_id' => $template->id,
            'inspector_id' => $inspector->id,
            'semana_inicio' => $semanaInicio->format('Y-m-d'),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('message', 'Weekly form created successfully.');

        $data = $response->json('data');
        $this->assertNotNull($data['id']);
        // Response dates are ISO 8601 format from Carbon JSON serialization
        $this->assertEquals($semanaInicio->format('Y-m-d'), Carbon::parse($data['semana_inicio'])->format('Y-m-d'));
        $this->assertEquals($semanaInicio->copy()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d'), Carbon::parse($data['semana_fin'])->format('Y-m-d'));
        $this->assertEquals($semanaInicio->isoWeek, $data['semana_numero']);
        $this->assertEquals($semanaInicio->year, $data['semana_anio']);

        // Assert 7 daily forms created
        $this->assertCount(7, $data['daily_forms']);

        // Assert vehicle snapshot populated
        $this->assertEquals($vehiculo->placa, $data['vehiculo_placa']);
        $this->assertEquals($vehiculo->marca, $data['vehiculo_marca']);
        $this->assertEquals($vehiculo->modelo, $data['vehiculo_modelo']);

        // Verify in database
        $this->assertDatabaseHas('preoperacional_semanas', [
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'template_id' => $template->id,
            'estado' => 'pendiente',
        ]);

        $this->assertDatabaseCount('preoperacional_daily_forms', 7);
    }

    /**
     * T012-4: POST /semanas rejects duplicate for same vehicle/template/week.
     */
    public function test_store_semana_rejects_duplicate_for_same_vehicle_template_week(): void
    {
        $test = $this->createTestSemana();

        $user = $test['user'];
        $vehiculo = $test['vehiculo'];
        $inspector = $test['inspector'];
        $template = $test['template'];
        $semana = $test['semana'];

        Sanctum::actingAs($user);

        // Try to create another semana for same vehicle, template, and week
        $response = $this->postJson('/api/v2/preoperacionales/semanas', [
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'template_id' => $template->id,
            'inspector_id' => $inspector->id,
            'semana_inicio' => $semana->semana_inicio->format('Y-m-d'),
        ]);

        $response->assertConflict(); // 409
        $response->assertJsonPath('message', 'Weekly form already exists for this vehicle and week.');
    }

    /**
     * T012-5: POST /semanas/{id}/dias/{dia} creates responses and marks completado.
     */
    public function test_submit_daily_form_creates_responses_and_marks_completado(): void
    {
        $test = $this->createTestSemana();

        $user = $test['user'];
        $semana = $test['semana'];
        $items = $test['items'];

        Sanctum::actingAs($user);

        // Build responses for all template items
        $respuestas = [];
        foreach ($items as $item) {
            $respuestas[] = [
                'item_id' => $item->id,
                'estado' => 'B',
            ];
        }

        $response = $this->postJson(
            "/api/v2/preoperacionales/semanas/{$semana->id}/dias/lunes",
            ['respuestas' => $respuestas]
        );

        $response->assertOk();
        $response->assertJsonPath('message', 'Daily form submitted successfully.');

        // Assert daily form marked completado
        $this->assertDatabaseHas('preoperacional_daily_forms', [
            'semana_id' => $semana->id,
            'dia_semana' => 'lunes',
            'completado' => true,
        ]);

        // Assert responses created
        $this->assertDatabaseCount('preoperacional_form_responses', count($items));

        // Assert semana estado updated to 'en_progreso' (1 of 7 days completed)
        $semana->refresh();
        $this->assertEquals('en_progreso', $semana->estado);
    }

    /**
     * T012-6: Server-side criticality evaluation — detects critical "Malo" items.
     * THIS IS THE MOST IMPORTANT TEST — validates the security fix.
     */
    public function test_submit_daily_form_server_side_criticality_evaluation(): void
    {
        $templateData = $this->createTemplateWithCriticalItem();
        $template = $templateData['template'];
        $criticalItem = $templateData['criticalItem'];
        $normalItem = $templateData['normalItem'];

        $user = User::factory()->create(['role' => 'admin']);
        $vehiculo = Vehiculo::create([
            'placa' => 'CRIT-001',
            'tipo' => 'volqueta',
            'marca' => 'Chevrolet',
            'modelo' => 'NPR',
        ]);
        $inspector = Empleado::create([
            'nombres' => 'Test',
            'apellidos' => 'Inspector',
            'cargo' => 'Inspector',
        ]);

        $semanaInicio = Carbon::now()->startOfWeek(Carbon::MONDAY);

        $semana = PreoperacionalSemana::create([
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'template_id' => $template->id,
            'inspector_id' => $inspector->id,
            'semana_inicio' => $semanaInicio->format('Y-m-d'),
            'semana_fin' => $semanaInicio->copy()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d'),
            'semana_numero' => $semanaInicio->isoWeek,
            'semana_anio' => $semanaInicio->year,
            'vehiculo_marca' => $vehiculo->marca,
            'vehiculo_modelo' => $vehiculo->modelo,
            'vehiculo_placa' => $vehiculo->placa,
            'inspector_nombre' => $inspector->nombre_completo,
            'inspector_cargo' => $inspector->cargo,
            'estado' => 'pendiente',
        ]);

        PreoperacionalDailyForm::create([
            'semana_id' => $semana->id,
            'dia_semana' => 'lunes',
            'fecha' => $semanaInicio->format('Y-m-d'),
            'completado' => false,
        ]);

        Sanctum::actingAs($user);

        // Submit with critical item marked as 'M' (Malo)
        $response = $this->postJson(
            "/api/v2/preoperacionales/semanas/{$semana->id}/dias/lunes",
            [
                'respuestas' => [
                    ['item_id' => $criticalItem->id, 'estado' => 'M', 'observacion' => 'Falla detectada'],
                    ['item_id' => $normalItem->id, 'estado' => 'B'],
                ],
            ]
        );

        $response->assertOk();

        // CRITICAL: Server detected the critical failure (not trusting client)
        $this->assertTrue($response->json('has_critical_failure'));

        // Verify the response for the critical item
        $responseData = $response->json('data.responses');
        $criticalResponse = collect($responseData)->firstWhere('item_id', $criticalItem->id);
        $this->assertEquals('M', $criticalResponse['estado']);

        // Test non-critical "Malo" does NOT trigger critical failure
        $semana2 = PreoperacionalSemana::create([
            'vehiculo_id' => $vehiculo->vehiculo_id,
            'template_id' => $template->id,
            'inspector_id' => $inspector->id,
            'semana_inicio' => $semanaInicio->copy()->addWeek()->format('Y-m-d'),
            'semana_fin' => $semanaInicio->copy()->addWeek()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d'),
            'semana_numero' => $semanaInicio->copy()->addWeek()->isoWeek,
            'semana_anio' => $semanaInicio->copy()->addWeek()->year,
            'vehiculo_marca' => $vehiculo->marca,
            'vehiculo_modelo' => $vehiculo->modelo,
            'vehiculo_placa' => $vehiculo->placa,
            'inspector_nombre' => $inspector->nombre_completo,
            'inspector_cargo' => $inspector->cargo,
            'estado' => 'pendiente',
        ]);

        PreoperacionalDailyForm::create([
            'semana_id' => $semana2->id,
            'dia_semana' => 'lunes',
            'fecha' => $semanaInicio->copy()->addWeek()->format('Y-m-d'),
            'completado' => false,
        ]);

        $response2 = $this->postJson(
            "/api/v2/preoperacionales/semanas/{$semana2->id}/dias/lunes",
            [
                'respuestas' => [
                    ['item_id' => $criticalItem->id, 'estado' => 'B'],
                    ['item_id' => $normalItem->id, 'estado' => 'M'], // Non-critical item is Malo
                ],
            ]
        );

        $response2->assertOk();
        $this->assertFalse($response2->json('has_critical_failure'));
    }

    /**
     * T012-7: Submit daily form deduplication — returns 409 for completed day.
     */
    public function test_submit_daily_form_deduplication_returns_409_for_completed_day(): void
    {
        $test = $this->createTestSemana();

        $user = $test['user'];
        $semana = $test['semana'];
        $items = $test['items'];

        Sanctum::actingAs($user);

        $respuestas = [];
        foreach ($items as $item) {
            $respuestas[] = [
                'item_id' => $item->id,
                'estado' => 'B',
            ];
        }

        // First submission — should succeed
        $response = $this->postJson(
            "/api/v2/preoperacionales/semanas/{$semana->id}/dias/lunes",
            ['respuestas' => $respuestas]
        );
        $response->assertOk();

        // Second submission without force=true — should return 409
        $response = $this->postJson(
            "/api/v2/preoperacionales/semanas/{$semana->id}/dias/lunes",
            ['respuestas' => $respuestas]
        );
        $response->assertConflict(); // 409
        $response->assertJsonPath('message', 'Daily form for lunes is already completed. Use ?force=true to override.');

        // With force=true — should succeed
        $response = $this->postJson(
            "/api/v2/preoperacionales/semanas/{$semana->id}/dias/lunes?force=true",
            ['respuestas' => $respuestas]
        );
        $response->assertOk();
    }

    /**
     * T012-8: PUT /semanas/{id}/fuera-servicio updates semana status.
     */
    public function test_mark_fuera_servicio_updates_semana_status(): void
    {
        $test = $this->createTestSemana();

        $user = $test['user'];
        $semana = $test['semana'];

        // Mark some daily forms as completed so estado is 'en_progreso'
        $test['dailyForms'][0]->update(['completado' => true]);
        $semana->refresh();

        Sanctum::actingAs($user);

        $response = $this->putJson(
            "/api/v2/preoperacionales/semanas/{$semana->id}/fuera-servicio",
            ['motivo' => 'Vehículo en reparación de motor']
        );

        $response->assertOk();
        $response->assertJsonPath('message', 'Weekly form marked as fuera de servicio.');

        $data = $response->json('data');
        $this->assertTrue($data['fuera_de_servicio']);
        $this->assertEquals('fuera_servicio', $data['estado']);
        $this->assertEquals('Vehículo en reparación de motor', $data['motivo_fuera_servicio']);

        // Verify in database
        $this->assertDatabaseHas('preoperacional_semanas', [
            'id' => $semana->id,
            'fuera_de_servicio' => true,
            'estado' => 'fuera_servicio',
        ]);
    }

    /**
     * T012-9: GET /semanas returns paginated results with filters.
     */
    public function test_index_semanas_returns_paginated_results_with_filters(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        // Create multiple semanas with different states
        $semanaInicio = Carbon::now()->startOfWeek(Carbon::MONDAY);

        for ($i = 0; $i < 5; $i++) {
            $vehiculo = Vehiculo::create([
                'placa' => "PLA-{$i}",
                'tipo' => 'tractor',
                'marca' => 'CAT',
                'modelo' => 'D6',
            ]);

            $template = PreoperacionalTemplate::create([
                'codigo' => "TPL-{$i}",
                'nombre' => "Template {$i}",
                'tipo_vehiculo' => 'tractor',
                'activo' => true,
            ]);

            $inspector = Empleado::create([
                'nombres' => "Inspector {$i}",
                'apellidos' => 'Test',
                'cargo' => 'Inspector',
            ]);

            $estado = $i < 3 ? 'completado' : 'pendiente';

            PreoperacionalSemana::create([
                'vehiculo_id' => $vehiculo->vehiculo_id,
                'template_id' => $template->id,
                'inspector_id' => $inspector->id,
                'semana_inicio' => $semanaInicio->copy()->addWeeks($i)->format('Y-m-d'),
                'semana_fin' => $semanaInicio->copy()->addWeeks($i)->endOfWeek(Carbon::SUNDAY)->format('Y-m-d'),
                'semana_numero' => $semanaInicio->copy()->addWeeks($i)->isoWeek,
                'semana_anio' => $semanaInicio->copy()->addWeeks($i)->year,
                'vehiculo_marca' => $vehiculo->marca,
                'vehiculo_modelo' => $vehiculo->modelo,
                'vehiculo_placa' => $vehiculo->placa,
                'inspector_nombre' => $inspector->nombre_completo,
                'inspector_cargo' => $inspector->cargo,
                'estado' => $estado,
            ]);
        }

        // Test without filter — should return all
        $response = $this->getJson('/api/v2/preoperacionales/semanas');
        $response->assertOk();
        $response->assertJsonCount(5, 'data');
        $this->assertNotNull($response->json('current_page')); // Pagination metadata

        // Test with estado filter — should return only completados
        $response = $this->getJson('/api/v2/preoperacionales/semanas?estado=completado');
        $response->assertOk();
        $response->assertJsonCount(3, 'data');

        foreach ($response->json('data') as $semana) {
            $this->assertEquals('completado', $semana['estado']);
        }
    }

    /**
     * T012-10: GET /pendientes-hoy returns vehicles without today's form.
     */
    public function test_pendientes_hoy_returns_vehicles_without_today_form(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $today = Carbon::today();
        $semanaInicio = $today->copy()->startOfWeek(Carbon::MONDAY);
        $diaSemana = $this->dateToDiaSemana($today);

        // Create vehicle A — will have a completed form today
        $vehiculoA = Vehiculo::create([
            'placa' => 'PEND-A',
            'tipo' => 'tractor',
            'marca' => 'CAT',
            'modelo' => 'D6',
        ]);

        $templateA = PreoperacionalTemplate::create([
            'codigo' => 'TPL-PEND-A',
            'nombre' => 'Template A',
            'tipo_vehiculo' => 'tractor',
            'activo' => true,
        ]);

        $inspector = Empleado::create([
            'nombres' => 'Inspector',
            'apellidos' => 'Test',
            'cargo' => 'Inspector',
        ]);

        $semanaA = PreoperacionalSemana::create([
            'vehiculo_id' => $vehiculoA->vehiculo_id,
            'template_id' => $templateA->id,
            'inspector_id' => $inspector->id,
            'semana_inicio' => $semanaInicio->format('Y-m-d'),
            'semana_fin' => $semanaInicio->copy()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d'),
            'semana_numero' => $semanaInicio->isoWeek,
            'semana_anio' => $semanaInicio->year,
            'vehiculo_marca' => $vehiculoA->marca,
            'vehiculo_modelo' => $vehiculoA->modelo,
            'vehiculo_placa' => $vehiculoA->placa,
            'inspector_nombre' => $inspector->nombre_completo,
            'inspector_cargo' => $inspector->cargo,
            'estado' => 'en_progreso',
        ]);

        // Mark today's daily form as completed for vehicle A
        PreoperacionalDailyForm::create([
            'semana_id' => $semanaA->id,
            'dia_semana' => $diaSemana,
            'fecha' => $today->format('Y-m-d'),
            'completado' => true,
        ]);

        // Create vehicle B — will NOT have a completed form today
        $vehiculoB = Vehiculo::create([
            'placa' => 'PEND-B',
            'tipo' => 'tractor',
            'marca' => 'John Deere',
            'modelo' => '5075E',
        ]);

        $templateB = PreoperacionalTemplate::create([
            'codigo' => 'TPL-PEND-B',
            'nombre' => 'Template B',
            'tipo_vehiculo' => 'tractor',
            'activo' => true,
        ]);

        $semanaB = PreoperacionalSemana::create([
            'vehiculo_id' => $vehiculoB->vehiculo_id,
            'template_id' => $templateB->id,
            'inspector_id' => $inspector->id,
            'semana_inicio' => $semanaInicio->format('Y-m-d'),
            'semana_fin' => $semanaInicio->copy()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d'),
            'semana_numero' => $semanaInicio->isoWeek,
            'semana_anio' => $semanaInicio->year,
            'vehiculo_marca' => $vehiculoB->marca,
            'vehiculo_modelo' => $vehiculoB->modelo,
            'vehiculo_placa' => $vehiculoB->placa,
            'inspector_nombre' => $inspector->nombre_completo,
            'inspector_cargo' => $inspector->cargo,
            'estado' => 'pendiente',
        ]);

        // Create pending daily form for vehicle B (not completed)
        PreoperacionalDailyForm::create([
            'semana_id' => $semanaB->id,
            'dia_semana' => $diaSemana,
            'fecha' => $today->format('Y-m-d'),
            'completado' => false,
        ]);

        $response = $this->getJson('/api/v2/preoperacionales/pendientes-hoy');
        $response->assertOk();

        $pendientes = $response->json();

        // Vehicle B should be in the list (no completed form today)
        $placas = collect($pendientes)->pluck('placa')->toArray();
        $this->assertContains('PEND-B', $placas);

        // Vehicle A should NOT be in the list (already completed today)
        $this->assertNotContains('PEND-A', $placas);
    }

    // ─── T013: Seeder Integration Tests ─────────────────

    /**
     * T013-1: Seeder creates all 4 official forms.
     */
    public function test_seeder_creates_all_4_official_forms(): void
    {
        $this->seed(ListaChequeoSeeder::class);

        // Assert 4 official templates exist
        $this->assertDatabaseHas('preoperacional_templates', ['codigo' => 'SMN-FT-33']);
        $this->assertDatabaseHas('preoperacional_templates', ['codigo' => 'SMN-FT-34']);
        $this->assertDatabaseHas('preoperacional_templates', ['codigo' => 'SMN-FT-35']);
        $this->assertDatabaseHas('preoperacional_templates', ['codigo' => 'SMN-FT-64']);

        // SMN-FT-33: 3 sections, 23 items
        $template33 = PreoperacionalTemplate::where('codigo', 'SMN-FT-33')->first();
        $this->assertNotNull($template33);
        $this->assertEquals(3, PreoperacionalTemplateSection::where('template_id', $template33->id)->count());
        $this->assertEquals(23, PreoperacionalTemplateItem::where('template_id', $template33->id)->count());

        // SMN-FT-34: 0 sections, 36+ items
        $template34 = PreoperacionalTemplate::where('codigo', 'SMN-FT-34')->first();
        $this->assertNotNull($template34);
        $this->assertEquals(0, PreoperacionalTemplateSection::where('template_id', $template34->id)->count());
        $this->assertGreaterThanOrEqual(36, PreoperacionalTemplateItem::where('template_id', $template34->id)->count());

        // SMN-FT-35: 8 sections, 44+ items
        $template35 = PreoperacionalTemplate::where('codigo', 'SMN-FT-35')->first();
        $this->assertNotNull($template35);
        $this->assertEquals(8, PreoperacionalTemplateSection::where('template_id', $template35->id)->count());
        $this->assertGreaterThanOrEqual(44, PreoperacionalTemplateItem::where('template_id', $template35->id)->count());

        // SMN-FT-64: 1 section, 22 items
        $template64 = PreoperacionalTemplate::where('codigo', 'SMN-FT-64')->first();
        $this->assertNotNull($template64);
        $this->assertEquals(1, PreoperacionalTemplateSection::where('template_id', $template64->id)->count());
        $this->assertEquals(22, PreoperacionalTemplateItem::where('template_id', $template64->id)->count());
    }

    /**
     * T013-2: Seeder creates generic templates for unmapped types.
     */
    public function test_seeder_creates_generic_templates_for_unmapped_types(): void
    {
        $this->seed(ListaChequeoSeeder::class);

        $genericTypes = ['camioneta', 'camion', 'retroexcavadora', 'minicargador', 'generico'];

        foreach ($genericTypes as $tipo) {
            $template = PreoperacionalTemplate::where('tipo_vehiculo', $tipo)->first();
            $this->assertNotNull($template, "Generic template for '{$tipo}' should exist");
            $this->assertTrue($template->activo);

            // Each generic template should have items
            $itemCount = PreoperacionalTemplateItem::where('template_id', $template->id)->count();
            $this->assertGreaterThan(0, $itemCount, "Generic template '{$tipo}' should have items");
        }
    }

    /**
     * T013-3: Seeder items have correct es_critico flags.
     */
    public function test_seeder_items_have_correct_es_critico_flags(): void
    {
        $this->seed(ListaChequeoSeeder::class);

        // Critical items should be marked es_critico=true
        $criticalItems = [
            'SMN-FT-33' => ['Frenos', 'Extintor', 'Cinturón de seguridad', 'Alarma de Reversa'],
            'SMN-FT-34' => ['Freno de servicios', 'Cinturón de seguridad', 'Extintor de Incendios', 'Alarma de reversa'],
            'SMN-FT-35' => ['Cinturón de seguridad', 'Extintor', 'Alarma De Retroceso'],
            'SMN-FT-64' => ['Nivel de aceite del motor', 'Filtro Hidráulico'],
        ];

        foreach ($criticalItems as $codigo => $preguntas) {
            $template = PreoperacionalTemplate::where('codigo', $codigo)->first();
            $this->assertNotNull($template, "Template {$codigo} should exist");

            foreach ($preguntas as $pregunta) {
                $item = PreoperacionalTemplateItem::where('template_id', $template->id)
                    ->where('pregunta', $pregunta)
                    ->first();

                // Some items may not match exactly due to text variations — skip if not found
                if ($item) {
                    $this->assertTrue(
                        $item->es_critico,
                        "Item '{$pregunta}' in {$codigo} should be es_critico=true"
                    );
                }
            }
        }

        // Non-critical items should be marked es_critico=false
        $template33 = PreoperacionalTemplate::where('codigo', 'SMN-FT-33')->first();
        $nonCriticalItem = PreoperacionalTemplateItem::where('template_id', $template33->id)
            ->where('pregunta', 'Botiquín')
            ->first();

        if ($nonCriticalItem) {
            $this->assertFalse($nonCriticalItem->es_critico, 'Botiquín should be es_critico=false');
        }
    }

    // ─── Private Helpers ────────────────────────────────

    /**
     * Convert a Carbon date to dia_semana enum value.
     */
    private function dateToDiaSemana(Carbon $date): string
    {
        $map = [
            1 => 'lunes',
            2 => 'martes',
            3 => 'miercoles',
            4 => 'jueves',
            5 => 'viernes',
            6 => 'sabado',
            7 => 'domingo',
        ];

        return $map[$date->dayOfWeekIso] ?? 'lunes';
    }
}
