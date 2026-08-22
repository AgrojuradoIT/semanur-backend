<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransaccionInventario extends Model
{
    protected $primaryKey = 'transaccion_id';

    protected $fillable = [
        'reverses_transaction_id',
        'producto_id',
        'bodega_id',
        'bodega_origen_id',
        'bodega_destino_id',
        'usuario_id',
        'client_operation_id',
        'client_operation_hash',
        'transaccion_tipo',
        'transaccion_cantidad',
        'transaccion_motivo',
        'transaccion_referencia_type',
        'transaccion_referencia_id',
        'transaccion_notas',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id', 'producto_id');
    }

    public function bodega()
    {
        return $this->belongsTo(Bodega::class, 'bodega_id', 'bodega_id');
    }

    public function bodegaOrigen()
    {
        return $this->belongsTo(Bodega::class, 'bodega_origen_id', 'bodega_id');
    }

    public function bodegaDestino()
    {
        return $this->belongsTo(Bodega::class, 'bodega_destino_id', 'bodega_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id', 'id');
    }

    public function referencia()
    {
        return $this->morphTo('transaccion_referencia');
    }

    public function transaccionRevertida()
    {
        return $this->belongsTo(TransaccionInventario::class, 'reverses_transaction_id', 'transaccion_id');
    }
}
