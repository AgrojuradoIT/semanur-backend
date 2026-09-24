<?php

namespace App\Models;

use App\Domain\InventoryOrders\PedidoEstado;
use App\Domain\InventoryOrders\RecepcionEstado;
use App\Domain\InventoryOrders\TransicionInvalidaException;
use App\Models\Concerns\HasOptimisticVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class PedidoInventario extends Model
{
    use HasOptimisticVersion;

    protected $table = 'pedidos_inventario';

    protected $primaryKey = 'pedido_id';

    protected $fillable = [
        'uuid',
        'numero',
        'proveedor_nombre_raw',
        'proveedor_nombre_confirmado',
        'proveedor_clave',
        'referencia_factura',
        'clave_documental_normalizada',
        'bodega_id',
        'estado',
        'version',
        'created_by',
        'submitted_by',
        'published_at',
        'cancelled_at',
        'cancel_motivo',
    ];

    protected $attributes = [
        'estado' => PedidoEstado::BORRADOR,
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $pedido): void {
            $pedido->uuid ??= (string) Str::uuid();
        });

        static::updating(function (self $pedido): void {
            if ($pedido->isDirty('uuid')) {
                throw new TransicionInvalidaException('El UUID público del pedido es inmutable.');
            }

            $estadoAnterior = (string) $pedido->getOriginal('estado');

            if ($pedido->isDirty('numero') && $estadoAnterior !== PedidoEstado::BORRADOR) {
                throw new TransicionInvalidaException('El número interno solo puede ajustarse en borrador.');
            }

            if (! PedidoEstado::esEditable($estadoAnterior)
                && $pedido->isDirty(['bodega_id', 'proveedor_nombre_confirmado', 'clave_documental_normalizada'])) {
                throw new TransicionInvalidaException('R02/R03: la bodega y el proveedor confirmados no se modifican tras publicar.');
            }

            if ($pedido->isDirty('estado') && ! PedidoEstado::permiteTransicion($estadoAnterior, (string) $pedido->estado)) {
                throw new TransicionInvalidaException(sprintf(
                    'Transición de pedido no autorizada: %s -> %s.',
                    $estadoAnterior,
                    (string) $pedido->estado,
                ));
            }
        });

        static::deleting(function (self $pedido): void {
            if ($pedido->estado !== PedidoEstado::BORRADOR) {
                throw new TransicionInvalidaException('R15: un pedido publicado no se elimina físicamente; solo puede cancelarse.');
            }
        });
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id', 'bodega_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function publicador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by', 'id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(PedidoInventarioLinea::class, 'pedido_id', 'pedido_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(PedidoDocumento::class, 'pedido_id', 'pedido_id');
    }

    public function recepciones(): HasMany
    {
        return $this->hasMany(PedidoRecepcion::class, 'pedido_id', 'pedido_id');
    }

    /**
     * Última sesión de recepción confirmada (recuperación tras recarga; plan
     * sección 17). Solo lectura: el detalle del pedido la expone cuando se
     * carga explícitamente esta relación.
     */
    public function ultimaRecepcionConfirmada(): HasOne
    {
        return $this->hasOne(PedidoRecepcion::class, 'pedido_id', 'pedido_id')
            ->where('estado', RecepcionEstado::CONFIRMADA)
            ->latestOfMany('recepcion_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(PedidoEvento::class, 'pedido_id', 'pedido_id');
    }

    public function alertas(): HasMany
    {
        return $this->hasMany(PedidoAlerta::class, 'pedido_id', 'pedido_id');
    }

    public function novedades(): HasManyThrough
    {
        return $this->hasManyThrough(
            PedidoNovedad::class,
            PedidoInventarioLinea::class,
            'pedido_id',
            'pedido_linea_id',
            'pedido_id',
            'linea_id',
        );
    }

    public function estaEnBorrador(): bool
    {
        return $this->estado === PedidoEstado::BORRADOR;
    }

    public function puedeTransicionarA(string $nuevoEstado): bool
    {
        return PedidoEstado::permiteTransicion((string) $this->estado, $nuevoEstado);
    }

    public function assertPuedeTransicionarA(string $nuevoEstado): void
    {
        if (! $this->puedeTransicionarA($nuevoEstado)) {
            throw new TransicionInvalidaException(sprintf(
                'Transición de pedido no autorizada: %s -> %s.',
                (string) $this->estado,
                $nuevoEstado,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    public function transicionarA(string $nuevoEstado, array $atributos = [], ?int $versionEsperada = null): void
    {
        if ($versionEsperada !== null) {
            $this->assertVersion($versionEsperada);
        }

        $this->assertPuedeTransicionarA($nuevoEstado);
        $this->fill($atributos);
        $this->estado = $nuevoEstado;
        $this->incrementarVersion();
        $this->save();
    }

    public function tieneIngresos(): bool
    {
        return $this->lineas()->whereHas('transacciones')->exists();
    }
}
