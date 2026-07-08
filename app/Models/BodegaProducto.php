<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class BodegaProducto extends Pivot
{
    protected $table = 'bodega_producto';
    public $timestamps = false;
    protected $fillable = ['bodega_id', 'producto_id', 'cantidad', 'last_updated'];
}
