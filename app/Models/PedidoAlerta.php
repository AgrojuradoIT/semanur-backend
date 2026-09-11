<?php

namespace App\Models;

use App\Domain\InventoryOrders\TransicionInvalidaException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoAlerta extends Model
{
    protected $table = 'pedido_alertas';

    protected $primaryKey = 'alerta_id';

    protected $fillable = [
        'event_uuid',
        'destinatario_user_id',
        'pedido_id',
        'tipo',
        'leida_at',
    ];

    protected function casts(): array
    {
        return [
            'leida_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $alerta): void {
            $permitidos = ['leida_at', 'updated_at'];

            foreach (array_keys($alerta->getDirty()) as $campo) {
                if (! in_array($campo, $permitidos, true)) {
                    throw new TransicionInvalidaException('La alerta solo admite marcarse como leída.');
                }
            }
        });
    }

    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinatario_user_id', 'id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoInventario::class, 'pedido_id', 'pedido_id');
    }

    public function marcarLeida(): void
    {
        if ($this->leida_at === null) {
            $this->leida_at = now();
            $this->save();
        }
    }
}
