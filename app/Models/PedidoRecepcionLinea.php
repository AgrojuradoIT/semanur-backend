<?php

namespace App\Models;

use App\Domain\InventoryOrders\InvariantePedidoException;
use App\Domain\InventoryOrders\TransicionInvalidaException;
use App\DTO\InventoryOrders\DecimalQuantity;
use App\Models\Concerns\HasOptimisticVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoRecepcionLinea extends Model
{
    use HasOptimisticVersion;

    protected $table = 'pedido_recepcion_lineas';

    protected $primaryKey = 'recepcion_linea_id';

    protected $fillable = [
        'recepcion_id',
        'pedido_linea_id',
        'conteo_total',
        'conteo_aptos',
        'estado_condicion',
        'motivo',
        'observacion_version',
        'count_confirmed',
        'actor_user_id',
        'receptor_empleado_id',
        'esperado_snapshot',
        'snapshots',
    ];

    protected $attributes = [
        'observacion_version' => 1,
        'count_confirmed' => false,
    ];

    protected function casts(): array
    {
        return [
            'observacion_version' => 'integer',
            'count_confirmed' => 'boolean',
            'conteo_total' => 'string',
            'conteo_aptos' => 'string',
            'esperado_snapshot' => 'string',
            'snapshots' => 'array',
        ];
    }

    protected function versionAttributeName(): string
    {
        return 'observacion_version';
    }

    protected static function booted(): void
    {
        static::saving(function (self $linea): void {
            foreach (['conteo_total', 'conteo_aptos'] as $campo) {
                if ($linea->{$campo} !== null && ! DecimalQuantity::isValid($linea->{$campo}, true)) {
                    throw new InvariantePedidoException("El campo {$campo} debe ser una cantidad decimal exacta mayor o igual a cero.");
                }
            }

            // SQLite devuelve decimales como int/float; se canonizan antes de
            // comparar para no rechazar valores válidos al guardar.
            $total = DecimalQuantity::tryNormalize($linea->conteo_total, true);
            $aptos = DecimalQuantity::tryNormalize($linea->conteo_aptos, true);

            if ($total !== null && $aptos !== null && DecimalQuantity::compare($aptos, $total) > 0) {
                throw new InvariantePedidoException('La cantidad apta no puede superar la cantidad total contada.');
            }
        });

        static::updating(function (self $linea): void {
            if ($linea->isDirty(['recepcion_id', 'pedido_linea_id'])) {
                throw new TransicionInvalidaException('La observación no cambia de recepción ni de línea.');
            }

            $recepcion = $linea->relationLoaded('recepcion') ? $linea->recepcion : $linea->recepcion()->first();
            $confirmada = $recepcion !== null && $recepcion->estaConfirmada();
            $camposObservacion = [
                'conteo_total',
                'conteo_aptos',
                'estado_condicion',
                'motivo',
                'esperado_snapshot',
                'snapshots',
                'actor_user_id',
                'receptor_empleado_id',
            ];

            if (($confirmada || $linea->getOriginal('count_confirmed')) && $linea->isDirty($camposObservacion)) {
                throw new TransicionInvalidaException('R11: la observación de conteo confirmada es inmutable.');
            }
        });

        static::deleting(function (self $linea): void {
            throw new TransicionInvalidaException('R15: las observaciones de conteo no se eliminan físicamente.');
        });
    }

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(PedidoRecepcion::class, 'recepcion_id', 'recepcion_id');
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(PedidoInventarioLinea::class, 'pedido_linea_id', 'linea_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'id');
    }

    public function receptorEmpleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'receptor_empleado_id', 'id');
    }

    public function novedades(): HasMany
    {
        return $this->hasMany(PedidoNovedad::class, 'recepcion_linea_id', 'recepcion_linea_id');
    }

    public function estaConfirmada(): bool
    {
        return (bool) $this->count_confirmed;
    }
}
