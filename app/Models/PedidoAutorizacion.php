<?php

namespace App\Models;

use App\Domain\InventoryOrders\InvariantePedidoException;
use App\Domain\InventoryOrders\TransicionInvalidaException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoAutorizacion extends Model
{
    public const ESTADO_VIGENTE = 'vigente';

    public const ESTADO_CONSUMIDA = 'consumida';

    public const ESTADO_INVALIDADA = 'invalidada';

    protected $table = 'pedido_autorizaciones';

    protected $primaryKey = 'autorizacion_id';

    protected $fillable = [
        'novedad_id',
        'actor_user_id',
        'empleado_id',
        'rol_snapshot',
        'cantidad_autorizada',
        'order_version',
        'line_version',
        'count_revision',
        'contexto_hash',
        'diferencia_cantidad',
        'importe',
        'precio_unitario_confirmado',
        'moneda',
        'base_precio',
        'motivo',
        'aceptacion_responsabilidad',
        'aceptada_at',
        'estado',
        'consumida_por_recepcion_id',
        'consumida_at',
    ];

    protected $attributes = [
        'estado' => self::ESTADO_VIGENTE,
    ];

    protected function casts(): array
    {
        return [
            'cantidad_autorizada' => 'string',
            'diferencia_cantidad' => 'string',
            'importe' => 'string',
            'precio_unitario_confirmado' => 'string',
            'order_version' => 'integer',
            'line_version' => 'integer',
            'count_revision' => 'integer',
            'aceptada_at' => 'datetime',
            'consumida_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $autorizacion): void {
            if (! (bool) config('inventory_orders.discrepancy_authorization_enabled', false)) {
                throw new InvariantePedidoException(
                    'D1: la autorización de desfase está deshabilitada; no se registra ninguna firma hasta confirmar el rol/persona autorizada.',
                );
            }
        });

        static::updating(function (self $autorizacion): void {
            $permitidos = ['estado', 'consumida_por_recepcion_id', 'consumida_at', 'updated_at'];

            foreach (array_keys($autorizacion->getDirty()) as $campo) {
                if (! in_array($campo, $permitidos, true)) {
                    throw new TransicionInvalidaException('La autorización de desfase es inmutable; solo se registra su consumo.');
                }
            }

            if ($autorizacion->isDirty('estado')) {
                $estadoAnterior = (string) $autorizacion->getOriginal('estado');
                if (! in_array((string) $autorizacion->estado, self::transicionesPermitidas($estadoAnterior), true)) {
                    throw new TransicionInvalidaException(sprintf(
                        'Transición de autorización no autorizada: %s -> %s.',
                        $estadoAnterior,
                        (string) $autorizacion->estado,
                    ));
                }
            }
        });

        static::deleting(function (self $autorizacion): void {
            throw new TransicionInvalidaException('R15: las autorizaciones no se eliminan físicamente.');
        });
    }

    /** @return list<string> */
    public static function transicionesPermitidas(string $estado): array
    {
        return match ($estado) {
            self::ESTADO_VIGENTE => [self::ESTADO_CONSUMIDA, self::ESTADO_INVALIDADA],
            default => [],
        };
    }

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(PedidoNovedad::class, 'novedad_id', 'novedad_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'id');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id', 'id');
    }

    public function recepcionConsumidora(): BelongsTo
    {
        return $this->belongsTo(PedidoRecepcion::class, 'consumida_por_recepcion_id', 'recepcion_id');
    }

    public function estaVigente(): bool
    {
        return $this->estado === self::ESTADO_VIGENTE;
    }

    public function consumir(PedidoRecepcion $recepcion): void
    {
        if (! $this->estaVigente()) {
            throw new TransicionInvalidaException('La autorización ya no está vigente.');
        }

        $this->estado = self::ESTADO_CONSUMIDA;
        $this->consumida_por_recepcion_id = $recepcion->recepcion_id;
        $this->consumida_at = now();
        $this->save();
    }
}
