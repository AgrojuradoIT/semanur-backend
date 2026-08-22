<?php

namespace App\Services\Authorization;

use App\Models\Media;
use App\Models\Novedad;
use App\Models\OrdenTrabajo;
use App\Models\PreoperacionalFormResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MediaContextAuthorization
{
    /**
     * @var array<string, array{model: class-string<Model>, key: string, read: string, write: string}>
     */
    private const CONTEXTS = [
        'taller:orden_trabajo' => [
            'model' => OrdenTrabajo::class,
            'key' => 'orden_trabajo_id',
            'read' => 'taller.read',
            'write' => 'taller.write',
        ],
        'programacion:novedad' => [
            'model' => Novedad::class,
            'key' => 'id',
            'read' => 'personal.read',
            'write' => 'personal.write',
        ],
        'checklists:preoperacional_respuesta' => [
            'model' => PreoperacionalFormResponse::class,
            'key' => 'id',
            'read' => 'checklists.read',
            'write' => 'checklists.write',
        ],
    ];

    public function __construct(
        private readonly OperationalAccess $access,
    ) {}

    public function authorizeContext(
        User $user,
        string $module,
        string $entityType,
        int $entityId,
        string $action,
    ): Model {
        $definition = self::CONTEXTS["{$module}:{$entityType}"] ?? null;

        if ($definition === null) {
            throw new HttpException(422, 'El contexto de archivo no es válido.');
        }

        $permission = $action === 'read' ? $definition['read'] : $definition['write'];
        abort_unless($user->hasPermission($permission), 403, 'No autorizado para este contexto.');

        /** @var Model|null $entity */
        $entity = $definition['model']::query()
            ->where($definition['key'], $entityId)
            ->first();

        abort_if($entity === null, 404, 'La entidad asociada no existe.');
        abort_unless($this->canAccessEntity($user, $entity), 403, 'No autorizado para esta entidad.');

        return $entity;
    }

    public function authorizeMedia(User $user, Media $media, string $action): Model
    {
        $entity = $this->authorizeContext(
            $user,
            (string) $media->module,
            (string) $media->entity_type,
            (int) $media->entity_id,
            $action === 'read' ? 'read' : 'write',
        );

        if ($action === 'delete') {
            $isCreator = $media->created_by !== null
                && (int) $media->created_by === (int) $user->getKey();

            abort_unless($isCreator || $this->access->isSupervisor($user), 403, 'No autorizado para archivar este archivo.');
        }

        return $entity;
    }

    private function canAccessEntity(User $user, Model $entity): bool
    {
        return match (true) {
            $entity instanceof OrdenTrabajo => $this->access->canAccessWorkOrder($user, $entity),
            $entity instanceof Novedad => $this->access->canAccessIncident($user, $entity),
            $entity instanceof PreoperacionalFormResponse => $this->canAccessPreoperationalResponse($user, $entity),
            default => false,
        };
    }

    private function canAccessPreoperationalResponse(User $user, PreoperacionalFormResponse $response): bool
    {
        $response->loadMissing('dailyForm.semana');
        $week = $response->dailyForm?->semana;

        return $week !== null && $this->access->canAccessPreoperational($user, $week);
    }
}
