<?php

namespace App\Models;

use App\Domain\InventoryOrders\TransicionInvalidaException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoDocumento extends Model
{
    protected $table = 'pedido_documentos';

    protected $primaryKey = 'documento_id';

    protected $fillable = [
        'pedido_id',
        'rol',
        'documento_revision',
        'storage_key',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'sha256',
        'created_by',
        'replaced_document_id',
        'pedido_linea_id',
    ];

    protected function casts(): array
    {
        return [
            'documento_revision' => 'integer',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $documento): void {
            throw new TransicionInvalidaException(
                'R15: los documentos de pedido son evidencia privada y no se eliminan físicamente; se sustituyen conservando el original.',
            );
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

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function reemplazado(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_document_id', 'documento_id');
    }

    public function reemplazos(): HasMany
    {
        return $this->hasMany(self::class, 'replaced_document_id', 'documento_id');
    }

    public function ocrIntentos(): HasMany
    {
        return $this->hasMany(PedidoOcrIntento::class, 'documento_id', 'documento_id');
    }

    public function novedades(): HasMany
    {
        return $this->hasMany(PedidoNovedad::class, 'documento_id', 'documento_id');
    }
}
