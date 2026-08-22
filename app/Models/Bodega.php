<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bodega extends Model
{
    protected $primaryKey = 'bodega_id';

    protected $fillable = ['nombre', 'descripcion', 'tipo'];

    public function productos()
    {
        return $this->belongsToMany(Producto::class, 'bodega_producto', 'bodega_id', 'producto_id')
            ->withPivot('cantidad')
            ->withTimestamps();
    }
}
