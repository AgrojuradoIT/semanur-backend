<?php

namespace App\Models;

use App\Domain\InventoryOrders\OcrIntentoEstado;
use App\Domain\InventoryOrders\TransicionInvalidaException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoOcrIntento extends Model
{
    protected $table = 'pedido_ocr_intentos';

    protected $primaryKey = 'intento_id';

    protected $fillable = [
        'documento_id',
        'documento_revision',
        'proveedor',
        'proveedor_version',
        'mapper_version',
        'clave_trabajo',
        'estado',
        'lease_token',
        'lease_expires_at',
        'fencing_token',
        'intentos',
        'max_intentos',
        'resultado',
        'referencia_privada',
        'error_code',
        'error_mensaje',
        'started_at',
        'finished_at',
    ];

    protected $attributes = [
        'estado' => OcrIntentoEstado::PENDIENTE,
    ];

    protected function casts(): array
    {
        return [
            'documento_revision' => 'integer',
            'fencing_token' => 'integer',
            'intentos' => 'integer',
            'max_intentos' => 'integer',
            'resultado' => 'array',
            'lease_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $intento): void {
            if ($intento->isDirty(['documento_id', 'documento_revision', 'clave_trabajo', 'proveedor'])) {
                throw new TransicionInvalidaException('La identidad del intento OCR es inmutable.');
            }

            if ($intento->isDirty('estado')) {
                $estadoAnterior = (string) $intento->getOriginal('estado');
                if (! OcrIntentoEstado::permiteTransicion($estadoAnterior, (string) $intento->estado)) {
                    throw new TransicionInvalidaException(sprintf(
                        'Transición de intento OCR no autorizada: %s -> %s.',
                        $estadoAnterior,
                        (string) $intento->estado,
                    ));
                }
            }
        });

        static::deleting(function (self $intento): void {
            throw new TransicionInvalidaException('R15: los intentos OCR no se eliminan físicamente.');
        });
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(PedidoDocumento::class, 'documento_id', 'documento_id');
    }

    public function puedeTransicionarA(string $nuevoEstado): bool
    {
        return OcrIntentoEstado::permiteTransicion((string) $this->estado, $nuevoEstado);
    }

    public function transicionarA(string $nuevoEstado): void
    {
        if (! $this->puedeTransicionarA($nuevoEstado)) {
            throw new TransicionInvalidaException(sprintf(
                'Transición de intento OCR no autorizada: %s -> %s.',
                (string) $this->estado,
                $nuevoEstado,
            ));
        }

        $this->estado = $nuevoEstado;
        $this->save();
    }
}
