<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemListaChequeo extends Model
{
    use HasFactory;

    protected $table = 'items_lista_chequeo';

    protected $fillable = [
        'lista_chequeo_id',
        'pregunta',
        'tipo_respuesta',
        'orden',
        'es_critico',
    ];

    public function listaChequeo()
    {
        return $this->belongsTo(ListaChequeo::class, 'lista_chequeo_id');
    }
}
