<?php

namespace App\Domain\InventoryOrders;

/**
 * Ya existe un intento OCR activo (pendiente/procesando) para el documento.
 * El endpoint de reproceso lo traduce a 409 sin crear un intento duplicado.
 */
class OcrIntentoActivoException extends DominioPedidoException
{
    public function __construct(private readonly ?int $intentoId = null)
    {
        parent::__construct('Ya existe un intento OCR activo para este documento.');
    }

    public function intentoId(): ?int
    {
        return $this->intentoId;
    }
}
