<?php

namespace Tests\Feature\Api;

use App\Models\Empleado;
use App\Models\ItemListaChequeo;
use App\Models\ListaChequeo;
use App\Models\PreoperacionalDailyForm;
use App\Models\PreoperacionalSemana;
use App\Models\PreoperacionalTemplate;
use App\Models\PreoperacionalTemplateItem;
use App\Models\User;
use App\Models\Vehiculo;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PreoperacionalAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_cannot_read_or_modify_another_inspectors_week(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['userA']);

        $this->getJson("/api/v2/preoperacionales/semanas/{$context['weekB']->id}")
            ->assertForbidden();

        $this->postJson("/api/v2/preoperacionales/semanas/{$context['weekB']->id}/dias/lunes", [
            'respuestas' => [[
                'item_id' => $context['item']->id,
                'estado' => 'B',
            ]],
        ])->assertForbidden();

        $this->assertDatabaseHas('preoperacional_daily_forms', [
            'semana_id' => $context['weekB']->id,
            'dia_semana' => 'lunes',
            'completado' => false,
        ]);
    }

    public function test_operator_cannot_impersonate_an_inspector_or_use_another_vehicle(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['userA']);

        $this->postJson('/api/v2/preoperacionales/semanas', [
            'vehiculo_id' => $context['vehicleB']->vehiculo_id,
            'template_id' => $context['template']->id,
            'inspector_id' => $context['employeeB']->id,
            'semana_inicio' => Carbon::now()->addWeek()->startOfWeek()->toDateString(),
        ])->assertForbidden();
    }

    public function test_only_supervisor_can_force_overwrite_or_mark_out_of_service(): void
    {
        $context = $this->context();
        $daily = PreoperacionalDailyForm::query()
            ->where('semana_id', $context['weekA']->id)
            ->where('dia_semana', 'lunes')
            ->firstOrFail();
        $daily->update(['completado' => true]);
        Sanctum::actingAs($context['userA']);

        $this->postJson("/api/v2/preoperacionales/semanas/{$context['weekA']->id}/dias/lunes?force=true", [
            'respuestas' => [[
                'item_id' => $context['item']->id,
                'estado' => 'B',
            ]],
        ])->assertForbidden();

        $this->putJson("/api/v2/preoperacionales/semanas/{$context['weekA']->id}/fuera-servicio", [
            'motivo' => 'Intento sin privilegio supervisor',
        ])->assertForbidden();

        $this->assertDatabaseHas('preoperacional_semanas', [
            'id' => $context['weekA']->id,
            'fuera_de_servicio' => false,
        ]);
    }

    public function test_week_index_is_scoped_to_the_authenticated_operator(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['userA']);

        $ids = collect($this->getJson('/api/v2/preoperacionales/semanas')
            ->assertOk()
            ->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($context['weekA']->id));
        $this->assertFalse($ids->contains($context['weekB']->id));
    }

    public function test_legacy_checklist_rejects_another_operator_and_vehicle(): void
    {
        $context = $this->context();
        $list = ListaChequeo::create([
            'nombre' => 'Legacy checklist',
            'tipo_vehiculo' => 'tractor',
            'activo' => true,
        ]);
        $item = ItemListaChequeo::create([
            'lista_chequeo_id' => $list->id,
            'pregunta' => 'Frenos',
            'tipo_respuesta' => 'cumple_falla',
        ]);
        Sanctum::actingAs($context['userA']);

        $this->postJson('/api/checklists', [
            'lista_chequeo_id' => $list->id,
            'vehiculo_id' => $context['vehicleB']->vehiculo_id,
            'operador_id' => $context['employeeB']->id,
            'respuestas' => [(string) $item->id => 'cumple'],
        ])->assertForbidden();

        $this->assertDatabaseCount('respuestas_lista_chequeo', 0);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $userA = User::factory()->create([
            'role' => 'operador',
            'permisos' => ['checklists.read', 'checklists.write'],
        ]);
        $userB = User::factory()->create([
            'role' => 'operador',
            'permisos' => ['checklists.read', 'checklists.write'],
        ]);
        $employeeA = Empleado::create([
            'nombres' => 'Operator A',
            'user_id' => $userA->id,
            'estado' => 'activo',
        ]);
        $employeeB = Empleado::create([
            'nombres' => 'Operator B',
            'user_id' => $userB->id,
            'estado' => 'activo',
        ]);
        $vehicleA = Vehiculo::create([
            'placa' => 'AUTH-A',
            'tipo' => 'tractor',
            'marca' => 'Test',
            'modelo' => 'Authorization A',
            'operador_asignado_id' => $employeeA->id,
        ]);
        $vehicleB = Vehiculo::create([
            'placa' => 'AUTH-B',
            'tipo' => 'tractor',
            'marca' => 'Test',
            'modelo' => 'Authorization B',
            'operador_asignado_id' => $employeeB->id,
        ]);
        $template = PreoperacionalTemplate::create([
            'codigo' => 'AUTH-TEMPLATE',
            'nombre' => 'Authorization template',
            'tipo_vehiculo' => 'tractor',
            'activo' => true,
        ]);
        $item = PreoperacionalTemplateItem::create([
            'template_id' => $template->id,
            'pregunta' => 'Frenos',
            'es_critico' => true,
        ]);

        $start = Carbon::now()->startOfWeek();
        $weekA = $this->createWeek($vehicleA, $employeeA, $template, $start);
        $weekB = $this->createWeek($vehicleB, $employeeB, $template, $start);

        return compact(
            'userA', 'userB', 'employeeA', 'employeeB',
            'vehicleA', 'vehicleB', 'template', 'item', 'weekA', 'weekB',
        );
    }

    private function createWeek(
        Vehiculo $vehicle,
        Empleado $employee,
        PreoperacionalTemplate $template,
        Carbon $start,
    ): PreoperacionalSemana {
        $week = PreoperacionalSemana::create([
            'vehiculo_id' => $vehicle->vehiculo_id,
            'template_id' => $template->id,
            'inspector_id' => $employee->id,
            'semana_inicio' => $start->toDateString(),
            'semana_fin' => $start->copy()->endOfWeek()->toDateString(),
            'semana_numero' => $start->isoWeek,
            'semana_anio' => $start->year,
            'vehiculo_placa' => $vehicle->placa,
            'inspector_nombre' => $employee->nombre_completo,
            'estado' => 'pendiente',
        ]);

        foreach (['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'] as $offset => $day) {
            PreoperacionalDailyForm::create([
                'semana_id' => $week->id,
                'dia_semana' => $day,
                'fecha' => $start->copy()->addDays($offset)->toDateString(),
                'completado' => false,
            ]);
        }

        return $week;
    }
}
