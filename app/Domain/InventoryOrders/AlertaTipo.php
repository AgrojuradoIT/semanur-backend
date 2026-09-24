<?php

namespace App\Domain\InventoryOrders;

/**
 * Tipos de alerta persistente Web del flujo de pedidos (plan sección 12).
 * No se relaciona con el pipeline genérico de Notificacion/FCM.
 */
final class AlertaTipo
{
    public const PEDIDO_PUBLICADO = 'pedido_publicado';

    public const PEDIDO_RETIRADO = 'pedido_retirado';

    public const PEDIDO_CANCELADO = 'pedido_cancelado';

    public const OCR_FALLIDO = 'ocr_fallido';

    public const REQUIERE_REVISION = 'requiere_revision';

    /** Novedades abiertas por una confirmación de recepción (U6). */
    public const NOVEDAD_ABIERTA = 'novedad_abierta';

    /** Resumen de recepción completa, todas las líneas ingresadas (U6). */
    public const PEDIDO_RECIBIDO = 'pedido_recibido';

    /** Resumen de recepción parcial: quedan líneas bloqueadas (U6). */
    public const RECIBIDO_CON_NOVEDAD = 'recibido_con_novedad';

    /** Resumen de recepción con al menos una excepción autorizada (U7). */
    public const RECIBIDO_CON_DESFASE = 'recibido_con_desfase';

    /** Firma de desfase registrada por bodega (U7; D1). */
    public const DESFASE_AUTORIZADO = 'desfase_autorizado';

    /** Respuesta documental de contabilidad sobre una novedad (U7). */
    public const RESPUESTA_CONTABLE = 'respuesta_contable';

    /** Aviso de entrega complementaria informada (U7). */
    public const ENTREGA_COMPLEMENTARIA_INFORMADA = 'entrega_complementaria_informada';

    /** @return list<string> */
    public static function todos(): array
    {
        return [
            self::PEDIDO_PUBLICADO,
            self::PEDIDO_RETIRADO,
            self::PEDIDO_CANCELADO,
            self::OCR_FALLIDO,
            self::REQUIERE_REVISION,
            self::NOVEDAD_ABIERTA,
            self::PEDIDO_RECIBIDO,
            self::RECIBIDO_CON_NOVEDAD,
            self::RECIBIDO_CON_DESFASE,
            self::DESFASE_AUTORIZADO,
            self::RESPUESTA_CONTABLE,
            self::ENTREGA_COMPLEMENTARIA_INFORMADA,
        ];
    }
}
