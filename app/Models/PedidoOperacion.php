<?php

namespace App\Models;

use App\Domain\InventoryOrders\TransicionInvalidaException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoOperacion extends Model
{
    protected $table = 'pedido_operaciones';

    protected $primaryKey = 'operacion_id';

    protected $fillable = [
        'actor_id',
        'command',
        'client_operation_id',
        'canonical_request_hash',
        'estado',
        'referencia',
        'resultado',
        'completada_at',
    ];

    protected function casts(): array
    {
        return [
            'resultado' => 'array',
            'completada_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $operacion): void {
            throw new TransicionInvalidaException('Las operaciones idempotentes no se eliminan físicamente.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id', 'id');
    }
}
