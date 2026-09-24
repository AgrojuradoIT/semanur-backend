<?php

namespace App\Models;

use App\Domain\InventoryOrders\RecepcionEstado;
use App\Domain\InventoryOrders\TransicionInvalidaException;
use App\Models\Concerns\HasOptimisticVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoRecepcion extends Model
{
    use HasOptimisticVersion;

    protected $table = 'pedido_recepciones';

    protected $primaryKey = 'recepcion_id';

    protected $fillable = [
        'pedido_id',
        'estado',
        'actor_user_id',
        'receptor_empleado_id',
        'version',
        'clave_operacion',
        'confirmada_at',
    ];

    protected $attributes = [
        'estado' => RecepcionEstado::BORRADOR,
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'confirmada_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $recepcion): void {
            if ($recepcion->isDirty('pedido_id')) {
                throw new TransicionInvalidaException('La recepción no cambia de pedido.');
            }

            if ($recepcion->isDirty('estado')) {
                $estadoAnterior = (string) $recepcion->getOriginal('estado');
                if (! RecepcionEstado::permiteTransicion($estadoAnterior, (string) $recepcion->estado)) {
                    throw new TransicionInvalidaException(sprintf(
                        'Transición de recepción no autorizada: %s -> %s.',
                        $estadoAnterior,
                        (string) $recepcion->estado,
                    ));
                }
            }
        });

        static::deleting(function (self $recepcion): void {
            throw new TransicionInvalidaException('R15: las recepciones no se eliminan físicamente.');
        });
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoInventario::class, 'pedido_id', 'pedido_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'id');
    }

    public function receptorEmpleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'receptor_empleado_id', 'id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(PedidoRecepcionLinea::class, 'recepcion_id', 'recepcion_id');
    }

    public function autorizacionesConsumidas(): HasMany
    {
        return $this->hasMany(PedidoAutorizacion::class, 'consumida_por_recepcion_id', 'recepcion_id');
    }

    public function estaConfirmada(): bool
    {
        return $this->estado === RecepcionEstado::CONFIRMADA;
    }

    public function puedeTransicionarA(string $nuevoEstado): bool
    {
        return RecepcionEstado::permiteTransicion((string) $this->estado, $nuevoEstado);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    public function transicionarA(string $nuevoEstado, array $atributos = [], ?int $versionEsperada = null): void
    {
        if ($versionEsperada !== null) {
            $this->assertVersion($versionEsperada);
        }

        if (! $this->puedeTransicionarA($nuevoEstado)) {
            throw new TransicionInvalidaException(sprintf(
                'Transición de recepción no autorizada: %s -> %s.',
                (string) $this->estado,
                $nuevoEstado,
            ));
        }

        $this->fill($atributos);
        $this->estado = $nuevoEstado;
        $this->incrementarVersion();
        $this->save();
    }
}
