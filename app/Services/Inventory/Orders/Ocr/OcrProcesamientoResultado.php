<?php

namespace App\Services\Inventory\Orders\Ocr;

/**
 * Resultado de una corrida de PedidoOcrService::procesar().
 *
 * `REINTENTAR` indica que el lease se liberó y el job debe soltarse con
 * backoff; `NO_OP` cubre jobs idempotentes (intento terminal, vigente o
 * resultado fenced/obsoleto); `CANCELADO` cubre pedido cancelado.
 */
final class OcrProcesamientoResultado
{
    public const COMPLETADO = 'completado';

    public const REQUIERE_REVISION = 'requiere_revision';

    public const REINTENTAR = 'reintentar';

    public const FALLIDO = 'fallido';

    public const NO_OP = 'no_op';

    public const CANCELADO = 'cancelado';

    private function __construct(
        public readonly string $resultado,
        public readonly ?string $errorCode = null,
        public readonly ?string $mensaje = null,
    ) {}

    public static function completado(): self
    {
        return new self(self::COMPLETADO);
    }

    public static function requiereRevision(): self
    {
        return new self(self::REQUIERE_REVISION);
    }

    public static function reintentar(string $errorCode, string $mensaje): self
    {
        return new self(self::REINTENTAR, $errorCode, $mensaje);
    }

    public static function fallido(string $errorCode, string $mensaje): self
    {
        return new self(self::FALLIDO, $errorCode, $mensaje);
    }

    public static function noOp(string $motivo): self
    {
        return new self(self::NO_OP, null, $motivo);
    }

    public static function cancelado(): self
    {
        return new self(self::CANCELADO);
    }

    public function esReintentable(): bool
    {
        return $this->resultado === self::REINTENTAR;
    }
}
