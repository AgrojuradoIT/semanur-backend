<?php

namespace App\Models;

use App\Domain\InventoryOrders\NovedadEstado;
use App\Domain\InventoryOrders\TransicionInvalidaException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoNovedad extends Model
{
    protected $table = 'pedido_novedades';

    protected $primaryKey = 'novedad_id';

    protected $fillable = [
        'pedido_linea_id',
        'recepcion_linea_id',
        'tipo',
        'motivo',
        'esperado_snapshot',
        'contado_snapshot',
        'aptos_snapshot',
        'estado',
        'responsable_empleado_id',
        'documento_id',
        'abierta_at',
        'cerrada_at',
    ];

    protected $attributes = [
        'estado' => NovedadEstado::ABIERTA,
    ];

    protected function casts(): array
    {
        return [
            'esperado_snapshot' => 'string',
            'contado_snapshot' => 'string',
            'aptos_snapshot' => 'string',
            'abierta_at' => 'datetime',
            'cerrada_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $novedad): void {
            if ($novedad->isDirty('pedido_linea_id')) {
                throw new TransicionInvalidaException('La novedad no cambia de línea.');
            }

            if ($novedad->isDirty('estado')) {
                $estadoAnterior = (string) $novedad->getOriginal('estado');
                if (! NovedadEstado::permiteTransicion($estadoAnterior, (string) $novedad->estado)) {
                    throw new TransicionInvalidaException(sprintf(
                        'Transición de novedad no autorizada: %s -> %s.',
                        $estadoAnterior,
                        (string) $novedad->estado,
                    ));
                }
            }
        });

        static::deleting(function (self $novedad): void {
            throw new TransicionInvalidaException('R15: la historia de novedades no se elimina físicamente.');
        });
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(PedidoInventarioLinea::class, 'pedido_linea_id', 'linea_id');
    }

    public function recepcionLinea(): BelongsTo
    {
        return $this->belongsTo(PedidoRecepcionLinea::class, 'recepcion_linea_id', 'recepcion_linea_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(PedidoDocumento::class, 'documento_id', 'documento_id');
    }

    public function responsableEmpleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'responsable_empleado_id', 'id');
    }

    public function autorizaciones(): HasMany
    {
        return $this->hasMany(PedidoAutorizacion::class, 'novedad_id', 'novedad_id');
    }

    public function estaAbierta(): bool
    {
        return $this->estado === NovedadEstado::ABIERTA;
    }

    public function puedeTransicionarA(string $nuevoEstado): bool
    {
        return NovedadEstado::permiteTransicion((string) $this->estado, $nuevoEstado);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    public function transicionarA(string $nuevoEstado, array $atributos = []): void
    {
        if (! $this->puedeTransicionarA($nuevoEstado)) {
            throw new TransicionInvalidaException(sprintf(
                'Transición de novedad no autorizada: %s -> %s.',
                (string) $this->estado,
                $nuevoEstado,
            ));
        }

        $this->fill($atributos);
        $this->estado = $nuevoEstado;
        $this->save();
    }
}
