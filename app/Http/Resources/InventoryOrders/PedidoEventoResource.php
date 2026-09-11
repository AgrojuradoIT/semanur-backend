<?php

namespace App\Http\Resources\InventoryOrders;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Evento del historial del pedido (U7): actor, tipo, revisión y cambios
 * relevantes. Nunca expone secretos (storage keys, rutas, tokens): las claves
 * reservadas se filtran recursivamente aunque un snapshot llegara a incluirlas.
 */
class PedidoEventoResource extends JsonResource
{
    /** @var list<string> */
    private const CLAVES_RESERVADAS = [
        'storage_key',
        'storage_path',
        'path',
        'url',
        'token',
        'secret',
        'password',
    ];

    public function toArray(Request $request): array
    {
        return [
            'evento_id' => $this->evento_id,
            'event_uuid' => $this->event_uuid,
            'event_type' => $this->event_type,
            'pedido_linea_id' => $this->pedido_linea_id,
            'actor_user_id' => $this->actor_user_id,
            'actor_empleado_id' => $this->actor_empleado_id,
            'actor_rol_snapshot' => $this->actor_rol_snapshot,
            'actor' => $this->whenLoaded('actor', fn (): ?array => $this->actor === null ? null : [
                'id' => (int) $this->actor->id,
                'nombre' => (string) $this->actor->name,
            ]),
            'revision' => $this->revision,
            'datos_antes' => $this->sinSecretos($this->datos_antes),
            'datos_despues' => $this->sinSecretos($this->datos_despues),
            'estado_despacho' => $this->estado_despacho,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $datos
     * @return array<string, mixed>|null
     */
    private function sinSecretos(?array $datos): ?array
    {
        if ($datos === null) {
            return null;
        }

        $limpio = [];

        foreach ($datos as $clave => $valor) {
            if (in_array(strtolower((string) $clave), self::CLAVES_RESERVADAS, true)) {
                continue;
            }

            $limpio[$clave] = is_array($valor) ? $this->sinSecretos($valor) : $valor;
        }

        return $limpio;
    }
}
