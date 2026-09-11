<?php

namespace App\Models;

use App\Domain\InventoryOrders\TransicionInvalidaException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PedidoEvento extends Model
{
    protected $table = 'pedido_eventos';

    protected $primaryKey = 'evento_id';

    protected $fillable = [
        'event_uuid',
        'pedido_id',
        'pedido_linea_id',
        'event_type',
        'actor_user_id',
        'actor_empleado_id',
        'actor_rol_snapshot',
        'datos_antes',
        'datos_despues',
        'command_id',
        'revision',
        'estado_despacho',
        'intentos_despacho',
        'despachado_at',
    ];

    protected $attributes = [
        'estado_despacho' => 'pendiente',
        'intentos_despacho' => 0,
    ];

    protected function casts(): array
    {
        return [
            'datos_antes' => 'array',
            'datos_despues' => 'array',
            'revision' => 'integer',
            'intentos_despacho' => 'integer',
            'despachado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $evento): void {
            $evento->event_uuid ??= (string) Str::uuid();
        });

        static::updating(function (self $evento): void {
            $permitidos = ['estado_despacho', 'intentos_despacho', 'despachado_at', 'updated_at'];

            foreach (array_keys($evento->getDirty()) as $campo) {
                if (! in_array($campo, $permitidos, true)) {
                    throw new TransicionInvalidaException('El contenido de auditoría de pedido_eventos es append-only; solo cambia el estado de despacho.');
                }
            }
        });

        static::deleting(function (self $evento): void {
            throw new TransicionInvalidaException('R15: los eventos de pedido son append-only y no se eliminan físicamente.');
        });
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoInventario::class, 'pedido_id', 'pedido_id');
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(PedidoInventarioLinea::class, 'pedido_linea_id', 'linea_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'id');
    }

    public function actorEmpleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'actor_empleado_id', 'id');
    }
}
