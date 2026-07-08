<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $primaryKey = 'producto_id';

    protected $fillable = [
        'categoria_id',
        'producto_sku',
        'referencia_fabrica',
        'producto_nombre',
        'producto_unidad_medida',
        'producto_stock_actual',
        'capacidad_maxima',
        'producto_alerta_stock_minimo',
        'producto_precio_costo',
        'producto_ubicacion',
    ];

    public function categoria()
    {
        return $this->belongsTo(Categoria::class, 'categoria_id', 'categoria_id');
    }

    public function transacciones()
    {
        return $this->hasMany(TransaccionInventario::class, 'producto_id', 'producto_id');
    }
}
