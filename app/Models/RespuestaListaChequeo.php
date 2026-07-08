<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RespuestaListaChequeo extends Model
{
    use HasFactory;

    protected $table = 'respuestas_lista_chequeo';

    protected $fillable = [
        'lista_chequeo_id',
        'vehiculo_id',
        'operador_id',
        'fecha',
        'respuestas',
        'estado',
        'observaciones_generales',
    ];

    protected $casts = [
        'respuestas' => 'array',
        'fecha' => 'datetime',
    ];

    public function listaChequeo()
    {
        return $this->belongsTo(ListaChequeo::class, 'lista_chequeo_id');
    }

    public function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class, 'vehiculo_id', 'vehiculo_id');
    }

    public function operador()
    {
        return $this->belongsTo(Empleado::class, 'operador_id');
    }
}
