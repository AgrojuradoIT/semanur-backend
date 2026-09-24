<?php

namespace Tests\Feature\Api;

use App\Models\Empleado;
use App\Models\Media;
use App\Models\OrdenTrabajo;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_context_is_closed_and_scoped_to_the_work_order(): void
    {
        Storage::fake('local');
        $context = $this->context();
        $otherMedia = Media::create([
            'module' => 'taller',
            'entity_type' => 'orden_trabajo',
            'entity_id' => $context['orderB']->orden_trabajo_id,
            'disk' => 'local',
            'path' => 'media/other.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 5,
            'created_by' => $context['userB']->id,
        ]);
        Storage::disk('local')->put('media/other.jpg', 'other');
        Sanctum::actingAs($context['userA']);

        $this->getJson('/api/media?'.http_build_query([
            'module' => 'taller',
            'entity_type' => 'orden_trabajo',
            'entity_id' => $context['orderB']->orden_trabajo_id,
        ]))->assertForbidden();

        $this->deleteJson("/api/media/{$otherMedia->id}")->assertForbidden();

        $this->postJson('/api/media', [
            'module' => 'unknown',
            'entity_type' => 'anything',
            'entity_id' => 1,
        ])->assertUnprocessable();
    }

    public function test_uploaded_evidence_is_private_downloaded_with_authorization_and_archived_losslessly(): void
    {
        Storage::fake('local');
        $context = $this->context();
        Sanctum::actingAs($context['userA']);

        $response = $this->post('/api/media', [
            'module' => 'taller',
            'entity_type' => 'orden_trabajo',
            'entity_id' => $context['orderA']->orden_trabajo_id,
            'notas' => 'Evidence',
            'client_operation_id' => (string) Str::uuid(),
            'file' => UploadedFile::fake()->image('evidence.jpg', 20, 20),
        ], ['Accept' => 'application/json'])->assertCreated();

        $response->assertJsonMissingPath('data.disk');
        $response->assertJsonMissingPath('data.path');
        $response->assertJsonPath('replayed', false);
        $response->assertJsonPath('data.url', url('/api/media/'.$response->json('data.id').'/content'));

        $media = Media::query()->findOrFail($response->json('data.id'));
        $this->assertSame('local', $media->disk);
        Storage::disk('local')->assertExists($media->path);

        $this->get("/api/media/{$media->id}/content", [
            'Accept' => 'image/jpeg',
        ])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->deleteJson("/api/media/{$media->id}")->assertOk();

        $this->assertSoftDeleted('media', ['id' => $media->id]);
        Storage::disk('local')->assertExists($media->path);
    }

    public function test_media_upload_is_idempotent_per_actor_and_rejects_uuid_payload_reuse(): void
    {
        Storage::fake('local');
        $context = $this->context();
        $operationId = (string) Str::uuid();
        Sanctum::actingAs($context['userA']);

        $payload = fn (string $notes, int $size = 20): array => [
            'module' => 'taller',
            'entity_type' => 'orden_trabajo',
            'entity_id' => $context['orderA']->orden_trabajo_id,
            'notas' => $notes,
            'client_operation_id' => $operationId,
            'file' => UploadedFile::fake()->image('work-order.jpg', $size, $size),
        ];

        $first = $this->post('/api/media', $payload('Same evidence'), [
            'Accept' => 'application/json',
        ])->assertCreated();

        $this->post('/api/media', $payload('Same evidence'), [
            'Accept' => 'application/json',
        ])->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('media', 1);

        $this->post('/api/media', $payload('Changed evidence', 30), [
            'Accept' => 'application/json',
        ])->assertConflict();

        $this->assertDatabaseCount('media', 1);

        Sanctum::actingAs($context['userB']);
        $this->post('/api/media', [
            'module' => 'taller',
            'entity_type' => 'orden_trabajo',
            'entity_id' => $context['orderB']->orden_trabajo_id,
            'notas' => 'Other actor',
            'client_operation_id' => $operationId,
            'file' => UploadedFile::fake()->image('other-actor.jpg', 20, 20),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertDatabaseCount('media', 2);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $userA = User::factory()->create([
            'role' => 'mecanico',
            'permisos' => ['media.read', 'media.write', 'taller.read', 'taller.write'],
        ]);
        $userB = User::factory()->create([
            'role' => 'mecanico',
            'permisos' => ['media.read', 'media.write', 'taller.read', 'taller.write'],
        ]);
        $employeeA = Empleado::create([
            'nombres' => 'Mechanic A',
            'user_id' => $userA->id,
            'estado' => 'activo',
        ]);
        $employeeB = Empleado::create([
            'nombres' => 'Mechanic B',
            'user_id' => $userB->id,
            'estado' => 'activo',
        ]);
        $vehicle = Vehiculo::create([
            'placa' => 'MEDIA-1',
            'tipo' => 'tractor',
            'marca' => 'Test',
            'modelo' => 'Media',
        ]);
        $orderA = OrdenTrabajo::create([
            'vehiculo_id' => $vehicle->vehiculo_id,
            'mecanico_asignado_id' => $employeeA->id,
            'fecha_inicio' => now(),
            'descripcion' => 'Order A',
        ]);
        $orderB = OrdenTrabajo::create([
            'vehiculo_id' => $vehicle->vehiculo_id,
            'mecanico_asignado_id' => $employeeB->id,
            'fecha_inicio' => now(),
            'descripcion' => 'Order B',
        ]);

        return compact('userA', 'userB', 'employeeA', 'employeeB', 'vehicle', 'orderA', 'orderB');
    }
}
