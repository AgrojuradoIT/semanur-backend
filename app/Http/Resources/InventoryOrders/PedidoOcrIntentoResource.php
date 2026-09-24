<?php

namespace App\Http\Resources\InventoryOrders;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representación pública de un intento OCR.
 *
 * NUNCA expone storage_key/rutas, clave_trabajo, lease_token, fencing_token,
 * referencia_privada ni payload crudo del proveedor. Solo estado, metadatos y
 * el contrato validado (proveedor, candidatos, advertencias, filas ignoradas).
 */
class PedidoOcrIntentoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $resultado = is_array($this->resultado) ? $this->resultado : null;

        return [
            'id' => $this->intento_id,
            'document_id' => $this->documento_id,
            'document_revision' => $this->documento_revision,
            'proveedor' => $this->proveedor,
            'proveedor_version' => $this->proveedor_version,
            'mapper_version' => $this->mapper_version,
            'schema_version' => $resultado['schema_version'] ?? null,
            'estado' => $this->estado,
            'intentos' => $this->intentos,
            'max_intentos' => $this->max_intentos,
            'error_code' => $this->error_code,
            'error_mensaje' => $this->error_mensaje,
            'requires_human_revision' => $resultado['requires_human_revision'] ?? null,
            'supplier' => $resultado['supplier'] ?? null,
            'lines' => $resultado['lines'] ?? [],
            'ignored_rows' => $resultado['ignored_rows'] ?? [],
            'warnings' => $resultado['warnings'] ?? [],
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
