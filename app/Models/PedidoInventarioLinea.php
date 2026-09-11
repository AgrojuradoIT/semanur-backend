<?php

namespace App\Models;

use App\Domain\InventoryOrders\InvariantePedidoException;
use App\Domain\InventoryOrders\LineaEstado;
use App\Domain\InventoryOrders\TransicionInvalidaException;
use App\DTO\InventoryOrders\DecimalQuantity;
use App\Models\Concerns\HasOptimisticVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PedidoInventarioLinea extends Model
{
    use HasOptimisticVersion;

    protected $table = 'pedido_inventario_lineas';

    protected $primaryKey = 'linea_id';

    protected $fillable = [
        'pedido_id',
        'parent_linea_id',
        'orden',
        'sku_raw',
        'sku_confirmado',
        'descripcion_raw',
        'descripcion_confirmada',
        'cantidad_esperada',
        'unidad',
        'producto_id',
        'categoria_propuesta_id',
        'alerta_minima_propuesta',
        'precio_costo_confirmado',
        'moneda',
        'base_precio',
        'estado',
        'version',
        'esperado_congelado_at',
    ];

    protected $attributes = [
        'estado' => LineaEstado::PENDIENTE,
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'version' => 'integer',
            'cantidad_esperada' => 'string',
            'alerta_minima_propuesta' => 'string',
            'precio_costo_confirmado' => 'string',
            'esperado_congelado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $linea): void {
            if ($linea->cantidad_esperada !== null && ! DecimalQuantity::isValid($linea->cantidad_esperada)) {
                throw new InvariantePedidoException(
                    'La cantidad esperada debe ser un decimal exacto mayor que cero con la escala del ledger.',
                );
            }
        });

        static::updating(function (self $linea): void {
            if ($linea->isDirty(['pedido_id', 'orden', 'parent_linea_id'])) {
                throw new TransicionInvalidaException('La pertenencia, el orden y el linaje de la línea son inmutables.');
            }

            if ($linea->isDirty(['sku_raw', 'sku_confirmado', 'cantidad_esperada']) && $linea->esperadoEstaCongelado()) {
                throw new TransicionInvalidaException('R03: el SKU y la cantidad esperada quedan congelados al publicar el pedido.');
            }

            if ($linea->isDirty('estado')) {
                $estadoAnterior = (string) $linea->getOriginal('estado');
                if (! LineaEstado::permiteTransicion($estadoAnterior, (string) $linea->estado)) {
                    throw new TransicionInvalidaException(sprintf(
                        'Transición de línea no autorizada: %s -> %s.',
                        $estadoAnterior,
                        (string) $linea->estado,
                    ));
                }
            }
        });

        static::deleting(function (self $linea): void {
            $pedido = $linea->pedido()->first();

            if ($pedido !== null && ! $pedido->estaEnBorrador()) {
                throw new TransicionInvalidaException('R15: no se eliminan líneas de un pedido publicado.');
            }
        });
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoInventario::class, 'pedido_id', 'pedido_id');
    }

    /**
     * Línea origen del saldo abierto (U10): null en líneas raíz, la línea
     * original en hijas de backorder. Inmutable una vez asignado.
     */
    public function lineaPadre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_linea_id', 'linea_id');
    }

    /**
     * Hijas de backorder abiertas desde esta línea (U10, recursivo: una hija
     * puede a su vez dividirse en un ingreso parcial posterior).
     */
    public function lineasHijas(): HasMany
    {
        return $this->hasMany(self::class, 'parent_linea_id', 'linea_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id', 'producto_id');
    }

    public function categoriaPropuesta(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_propuesta_id', 'categoria_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(PedidoDocumento::class, 'pedido_linea_id', 'linea_id');
    }

    public function recepcionLineas(): HasMany
    {
        return $this->hasMany(PedidoRecepcionLinea::class, 'pedido_linea_id', 'linea_id');
    }

    public function novedades(): HasMany
    {
        return $this->hasMany(PedidoNovedad::class, 'pedido_linea_id', 'linea_id');
    }

    public function autorizaciones(): HasManyThrough
    {
        return $this->hasManyThrough(
            PedidoAutorizacion::class,
            PedidoNovedad::class,
            'pedido_linea_id',
            'novedad_id',
            'linea_id',
            'novedad_id',
        );
    }

    public function transaccion(): HasOne
    {
        return $this->hasOne(TransaccionInventario::class, 'pedido_linea_id', 'linea_id');
    }

    public function transacciones(): HasMany
    {
        return $this->hasMany(TransaccionInventario::class, 'pedido_linea_id', 'linea_id');
    }

    public function esperadoEstaCongelado(): bool
    {
        if ($this->esperado_congelado_at !== null) {
            return true;
        }

        $pedido = $this->relationLoaded('pedido') ? $this->pedido : $this->pedido()->first();

        return $pedido !== null && ! $pedido->estaEnBorrador();
    }

    public function puedeTransicionarA(string $nuevoEstado): bool
    {
        return LineaEstado::permiteTransicion((string) $this->estado, $nuevoEstado);
    }

    public function assertPuedeTransicionarA(string $nuevoEstado): void
    {
        if (! $this->puedeTransicionarA($nuevoEstado)) {
            throw new TransicionInvalidaException(sprintf(
                'Transición de línea no autorizada: %s -> %s.',
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

    public function tieneIngreso(): bool
    {
        return $this->transacciones()->exists();
    }
}
