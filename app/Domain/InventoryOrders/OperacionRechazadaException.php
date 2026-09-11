<?php

namespace App\Domain\InventoryOrders;

use DomainException;

/**
 * Rechazo de negocio de una operación de pedido (publicación, retiro,
 * cancelación) con un estado HTTP explícito y contexto opcional para la API.
 *
 * No se usa para errores de validación de payload: esos viajan como
 * ValidationException (422). Aquí viven las puertas operativas (409) y los
 * datos faltantes de precondición (422 con mensaje propio).
 */
class OperacionRechazadaException extends DomainException
{
    /**
     * @param  array<string, mixed>  $contexto
     */
    public function __construct(
        public readonly int $httpStatus,
        string $mensaje,
        public readonly array $contexto = [],
    ) {
        parent::__construct($mensaje);
    }
}
