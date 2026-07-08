<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ListaChequeo extends Model
{
    use HasFactory;

    protected $table = 'listas_chequeo';

    protected $fillable = [
        'nombre',
        'descripcion',
        'tipo_vehiculo',
        'activo',
    ];

    public function items()
    {
        return $this->hasMany(ItemListaChequeo::class, 'lista_chequeo_id')->orderBy('orden');
    }

    public function respuestas()
    {
        return $this->hasMany(RespuestaListaChequeo::class, 'lista_chequeo_id');
    }
}
