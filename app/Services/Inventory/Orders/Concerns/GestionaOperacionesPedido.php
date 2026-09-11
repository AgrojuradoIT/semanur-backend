<?php

namespace App\Services\Inventory\Orders\Concerns;

use App\Domain\InventoryOrders\OperacionRechazadaException;
use App\Models\PedidoOperacion;

/**
 * Idempotencia compartida de las mutaciones de pedido (plan sección 9):
 * unique(actor_id, command, client_operation_id), hash canónico y payload de
 * replay estable. Mismo UUID + mismo hash = replay; mismo UUID + hash distinto
 * = 409 sin mutaciones.
 */
trait GestionaOperacionesPedido
{
    protected function buscarOperacionPedido(
        int $actorId,
        string $command,
        string $operationId,
        bool $lock = false,
    ): ?PedidoOperacion {
        $query = PedidoOperacion::query()
            ->where('actor_id', $actorId)
            ->where('command', $command)
            ->where('client_operation_id', $operationId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $canonical
     */
    protected function hashOperacionPedido(array $canonical): string
    {
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $resultado
     */
    protected function registrarOperacionPedido(
        int $actorId,
        string $command,
        string $operationId,
        string $hash,
        string $referencia,
        array $resultado,
    ): PedidoOperacion {
        return PedidoOperacion::create([
            'actor_id' => $actorId,
            'command' => $command,
            'client_operation_id' => $operationId,
            'canonical_request_hash' => $hash,
            'estado' => 'completada',
            'referencia' => $referencia,
            'resultado' => $resultado,
            'completada_at' => now(),
        ]);
    }

    /**
     * @return array{operacion: PedidoOperacion, replay: true}
     */
    protected function replayOperacionPedido(PedidoOperacion $operacion, string $hash): array
    {
        if (! hash_equals((string) $operacion->canonical_request_hash, $hash)) {
            throw new OperacionRechazadaException(
                409,
                'client_operation_id ya fue utilizado con un contenido diferente.',
            );
        }

        return ['operacion' => $operacion, 'replay' => true];
    }
}
