<?php

namespace App\Services\Inventory\Orders\Ocr;

/**
 * Proveedor OCR no habilitado.
 *
 * Es un RuntimeException con error_code terminal: el intento pasa a `fallido`
 * sin reintentos y sin ninguna llamada de red. Se lanza cuando la configuración
 * apunta a `google` (adaptador real diferido: falta SDK, credenciales/región y
 * autorización D2) o a un proveedor desconocido.
 */
final class OcrProveedorNoHabilitadoException extends OcrException
{
    public const ERROR_CODE = 'proveedor_no_habilitado';

    public function __construct(string $mensaje = 'proveedor_no_habilitado: el proveedor OCR configurado no está habilitado.')
    {
        parent::__construct(self::ERROR_CODE, $mensaje, false);
    }
}
