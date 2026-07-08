<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransaccionInventario extends Model
{
    protected $primaryKey = 'transaccion_id';

    protected $fillable = [
        'producto_id',
        'bodega_id',
        'usuario_id',
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

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id', 'id');
    }

    public function referencia()
    {
        return $this->morphTo('transaccion_referencia');
    }
}
