<?php

namespace App\Http\Resources\InventoryOrders;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoInventarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'pedido_id' => $this->pedido_id,
            'uuid' => $this->uuid,
            'numero' => $this->numero,
            'proveedor_nombre_raw' => $this->proveedor_nombre_raw,
            'proveedor_nombre_confirmado' => $this->proveedor_nombre_confirmado,
            'proveedor_clave' => $this->proveedor_clave,
            'referencia_factura' => $this->referencia_factura,
            'bodega_id' => $this->bodega_id,
            'bodega' => $this->whenLoaded('bodega', fn (): ?array => $this->bodega === null ? null : [
                'bodega_id' => $this->bodega->bodega_id,
                'nombre' => $this->bodega->nombre,
                'tipo' => $this->bodega->tipo,
            ]),
            'estado' => $this->estado,
            'version' => $this->version,
            'created_by' => $this->created_by,
            'submitted_by' => $this->submitted_by,
            'published_at' => $this->published_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancel_motivo' => $this->cancel_motivo,
            'lineas' => $this->whenLoaded('lineas', fn (): array => PedidoInventarioLineaResource::collection(
                $this->lineas->sortBy('orden')->values()
            )->resolve($request)),
            'lineas_count' => $this->whenCounted('lineas'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            // Evidencia privada referenciada (solo metadatos y content_url
            // autorizado; nunca storage_key). Solo en el detalle.
            'documentos' => $this->whenLoaded('documentos', fn (): array => PedidoDocumentoResource::collection(
                $this->documentos->sortBy('documento_id')->values()
            )->resolve($request)),
            // Novedades vigentes del pedido (U7): E/C/A snapshot y estado.
            // Solo lectura; las acciones se autorizan por Policy en los
            // endpoints de respuestas/firma.
            'novedades' => $this->whenLoaded('novedades', fn (): array => $this->novedades
                ->sortBy('novedad_id')
                ->values()
                ->map(fn ($novedad): array => [
                    'novedad_id' => $novedad->novedad_id,
                    'pedido_linea_id' => $novedad->pedido_linea_id,
                    'tipo' => (string) $novedad->tipo,
                    'estado' => (string) $novedad->estado,
                    'motivo' => $novedad->motivo,
                    'esperado_snapshot' => $novedad->esperado_snapshot,
                    'contado_snapshot' => $novedad->contado_snapshot,
                    'aptos_snapshot' => $novedad->aptos_snapshot,
                    'documento_id' => $novedad->documento_id,
                    'abierta_at' => $novedad->abierta_at?->toISOString(),
                    'cerrada_at' => $novedad->cerrada_at?->toISOString(),
                ])
                ->all()),
            // Recuperación tras recarga (plan sección 17): última recepción
            // confirmada si el detalle la cargó; nunca en la lista.
            'ultima_recepcion_confirmada' => $this->whenLoaded(
                'ultimaRecepcionConfirmada',
                fn (): ?array => $this->ultimaRecepcionConfirmada === null ? null : [
                    'recepcion_id' => $this->ultimaRecepcionConfirmada->recepcion_id,
                    'estado' => (string) $this->ultimaRecepcionConfirmada->estado,
                    'version' => $this->ultimaRecepcionConfirmada->versionActual(),
                    'confirmada_at' => $this->ultimaRecepcionConfirmada->confirmada_at?->toISOString(),
                    'receptor_empleado_id' => $this->ultimaRecepcionConfirmada->receptor_empleado_id,
                ],
            ),
            // Solo en detalle o mutaciones (la lista no carga líneas): evita
            // consultas por fila y refleja lo que la Policy permite aquí y ahora.
            'acciones' => $this->when(
                $user !== null && $this->resource->relationLoaded('lineas'),
                fn (): array => [
                    'editar' => $user->can('update', $this->resource),
                    'publicar' => $user->can('publish', $this->resource),
                    'retirar' => $user->can('returnForCorrection', $this->resource),
                    'cancelar' => $user->can('cancel', $this->resource),
                    'recibir' => $user->can('receive', $this->resource),
                    'completar_ficha' => $user->can('completeCatalog', $this->resource),
                    'responder_novedad' => $user->can('respondToDiscrepancy', $this->resource),
                    'autorizar_desfase' => $user->can('authorizeDiscrepancy', $this->resource),
                ],
            ),
        ];
    }
}
