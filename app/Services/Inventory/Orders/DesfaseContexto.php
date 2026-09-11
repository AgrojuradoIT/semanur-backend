<?php

namespace App\Services\Inventory\Orders;

use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoRecepcionLinea;

/**
 * Versioned context of a discrepancy signature (U7; D1).
 *
 * The hash binds a signature to the exact order/line/count revision it was
 * rendered for. Any later change of count, condition, motivo or version makes
 * the recomputed hash differ, so the confirmation invalidates the authorization
 * instead of ingesting stock (R12). Fields are canonical strings; no floats.
 */
final class DesfaseContexto
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function capturar(
        PedidoInventario $orden,
        PedidoInventarioLinea $linea,
        PedidoRecepcionLinea $observacion,
    ): array {
        return [
            'order_version' => $orden->versionActual(),
            'line_version' => $linea->versionActual(),
            'observacion_version' => (int) $observacion->observacion_version,
            'conteo_total' => (string) PedidoSnapshot::cantidadCanonica($observacion->conteo_total),
            'conteo_aptos' => (string) PedidoSnapshot::cantidadCanonica($observacion->conteo_aptos),
            'estado_condicion' => (string) $observacion->estado_condicion,
            // El motivo del CONTEO forma parte del contexto: un recuento con
            // otro motivo (aunque los números coincidan) invalida la firma.
            'motivo' => trim((string) $observacion->motivo),
        ];
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    public static function hash(array $contexto): string
    {
        return hash('sha256', json_encode($contexto, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
