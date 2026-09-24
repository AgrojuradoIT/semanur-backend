<?php

namespace App\Services\Inventory\Orders\Ocr;

use RuntimeException;
use Throwable;

/**
 * Error clasificado del pipeline OCR.
 *
 * `errorCode` es un identificador estable y acotado (columna error_code, 80
 * caracteres) que se persiste en el intento. `reintentable` distingue fallos
 * transitorios (timeout, 429, 5xx) de errores terminales (4xx, credenciales,
 * contrato inválido). Nunca se persisten payloads crudos del proveedor.
 */
class OcrException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $mensaje,
        private readonly bool $reintentable = false,
        ?Throwable $anterior = null,
    ) {
        parent::__construct($mensaje, 0, $anterior);
    }

    public static function reintentable(string $errorCode, string $mensaje, ?Throwable $anterior = null): self
    {
        return new self($errorCode, $mensaje, true, $anterior);
    }

    public static function terminal(string $errorCode, string $mensaje, ?Throwable $anterior = null): self
    {
        return new self($errorCode, $mensaje, false, $anterior);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function esReintentable(): bool
    {
        return $this->reintentable;
    }
}
