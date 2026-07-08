<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    protected $primaryKey = 'categoria_id';

    protected $fillable = [
        'categoria_nombre',
        'categoria_tipo',
        'categoria_descripcion',
    ];

    public function productos()
    {
        return $this->hasMany(Producto::class, 'categoria_id', 'categoria_id');
    }
}
