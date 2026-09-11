<?php

namespace App\Services\Inventory\Orders\Ocr;

/**
 * Stub del adaptador Google Cloud Vision (DOCUMENT_TEXT_DETECTION).
 *
 * DECISIÓN D2: el proveedor real está diferido a un paso separado porque exige
 * el SDK de Google, cambios en composer.json y credenciales/región autorizadas.
 * Este stub NO importa SDK, NO usa HTTP y NO toca la red: falla de inmediato con
 * un error terminal claro para que el intento quede `fallido` con
 * error_code `proveedor_no_habilitado`.
 *
 * Se registra únicamente cuando `inventory_orders.ocr.provider === 'google'`
 * (ver PedidoOcrProviderFactory).
 */
final class GoogleVisionPedidoOcrProvider implements PedidoOcrProvider
{
    public const PROVIDER_NAME = 'google';

    public function extraer(string $rutaAbsoluta, string $mimeType, array $contexto = []): array
    {
        throw new OcrProveedorNoHabilitadoException(
            'proveedor_no_habilitado: el adaptador real de Google Cloud Vision '
            .'(DOCUMENT_TEXT_DETECTION) no está habilitado: falta SDK, credenciales/región '
            .'y autorización expresa para enviar evidencia. No se realizó ninguna llamada de red.',
        );
    }

    public function nombre(): string
    {
        return self::PROVIDER_NAME;
    }

    public function version(): ?string
    {
        return null;
    }
}
