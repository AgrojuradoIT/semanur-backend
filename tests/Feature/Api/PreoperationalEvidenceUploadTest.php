<?php

namespace Tests\Feature\Api;

use App\Models\Empleado;
use App\Models\Media;
use App\Models\PreoperacionalDailyForm;
use App\Models\PreoperacionalFormResponse;
use App\Models\PreoperacionalSemana;
use App\Models\PreoperacionalTemplate;
use App\Models\PreoperacionalTemplateItem;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PreoperationalEvidenceUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_evidence_is_private_object_authorized_and_idempotent(): void
    {
        Storage::fake('local');
        $context = $this->context();
        $operationId = (string) Str::uuid();
        Sanctum::actingAs($context['userA']);

        $first = $this->post('/api/media', [
            'module' => 'checklists',
            'entity_type' => 'preoperacional_respuesta',
            'entity_id' => $context['response']->id,
            'client_operation_id' => $operationId,
            'notas' => 'Falla visible en freno',
            'file' => UploadedFile::fake()->image('brake.png', 20, 20),
        ], ['Accept' => 'application/json'])->assertCreated();

        $mediaId = $first->json('data.id');
        $first->assertJsonPath('replayed', false)
            ->assertJsonPath('client_operation_id', strtolower($operationId))
            ->assertJsonPath('data.module', 'checklists')
            ->assertJsonPath('data.entity_type', 'preoperacional_respuesta');

        $media = Media::query()->findOrFail($mediaId);
        $this->assertSame('local', $media->disk);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $media->content_sha256);
        Storage::disk('local')->assertExists($media->path);
        $this->assertSame($media->url, $context['response']->refresh()->foto_url);

        $this->post('/api/media', [
            'module' => 'checklists',
            'entity_type' => 'preoperacional_respuesta',
            'entity_id' => $context['response']->id,
            'client_operation_id' => strtoupper($operationId),
            'notas' => 'Falla visible en freno',
            'file' => UploadedFile::fake()->image('brake-retry.png', 20, 20),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('data.id', $mediaId);

        $this->assertDatabaseCount('media', 1);

        $this->post('/api/media', [
            'module' => 'checklists',
            'entity_type' => 'preoperacional_respuesta',
            'entity_id' => $context['response']->id,
            'client_operation_id' => $operationId,
            'notas' => 'Contenido cambiado',
            'file' => UploadedFile::fake()->image('changed.png', 30, 30),
        ], ['Accept' => 'application/json'])->assertConflict();

        $this->assertDatabaseCount('media', 1);

        Sanctum::actingAs($context['userB']);
        $this->post('/api/media', [
            'module' => 'checklists',
            'entity_type' => 'preoperacional_respuesta',
            'entity_id' => $context['response']->id,
            'client_operation_id' => (string) Str::uuid(),
            'file' => UploadedFile::fake()->image('foreign.png', 20, 20),
        ], ['Accept' => 'application/json'])->assertForbidden();

        Sanctum::actingAs($context['userA']);
        $this->get("/api/media/{$mediaId}/content", ['Accept' => 'image/png'])
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->deleteJson("/api/media/{$mediaId}")->assertOk();
        $this->assertSoftDeleted('media', ['id' => $mediaId]);
        $this->assertNull($context['response']->refresh()->foto_url);
        Storage::disk('local')->assertExists($media->path);

        $this->post('/api/media', [
            'module' => 'checklists',
            'entity_type' => 'preoperacional_respuesta',
            'entity_id' => $context['response']->id,
            'client_operation_id' => $operationId,
            'notas' => 'Falla visible en freno',
            'file' => UploadedFile::fake()->image('archived-retry.png', 20, 20),
        ], ['Accept' => 'application/json'])->assertConflict();
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
            'nombres' => 'Inspector A',
            'user_id' => $userA->id,
            'estado' => 'activo',
        ]);
        $employeeB = Empleado::create([
            'nombres' => 'Inspector B',
            'user_id' => $userB->id,
            'estado' => 'activo',
        ]);
        $vehicle = Vehiculo::create([
            'placa' => 'EVID-1',
            'tipo' => 'tractor',
            'marca' => 'Test',
            'modelo' => 'Evidence',
            'operador_asignado_id' => $employeeA->id,
        ]);
        $template = PreoperacionalTemplate::create([
            'codigo' => 'EVIDENCE-TEMPLATE',
            'nombre' => 'Evidence template',
            'tipo_vehiculo' => 'tractor',
            'activo' => true,
        ]);
        $item = PreoperacionalTemplateItem::create([
            'template_id' => $template->id,
            'pregunta' => 'Frenos',
            'es_critico' => true,
        ]);
        $week = PreoperacionalSemana::create([
            'vehiculo_id' => $vehicle->vehiculo_id,
            'template_id' => $template->id,
            'inspector_id' => $employeeA->id,
            'semana_inicio' => now()->startOfWeek()->toDateString(),
            'semana_fin' => now()->endOfWeek()->toDateString(),
            'semana_numero' => now()->isoWeek,
            'semana_anio' => now()->year,
            'vehiculo_placa' => $vehicle->placa,
            'inspector_nombre' => $employeeA->nombre_completo,
            'estado' => 'en_progreso',
        ]);
        $daily = PreoperacionalDailyForm::create([
            'semana_id' => $week->id,
            'dia_semana' => 'lunes',
            'fecha' => now()->startOfWeek()->toDateString(),
            'completado' => true,
        ]);
        $response = PreoperacionalFormResponse::create([
            'daily_form_id' => $daily->id,
            'item_id' => $item->id,
            'estado' => 'M',
            'observacion' => 'Brake failure',
        ]);

        return compact(
            'userA',
            'userB',
            'employeeA',
            'employeeB',
            'vehicle',
            'template',
            'item',
            'week',
            'daily',
            'response',
        );
    }
}
