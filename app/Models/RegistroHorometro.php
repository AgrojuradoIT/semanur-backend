<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistroHorometro extends Model
{
    protected $table = 'registros_horometro';
    protected $primaryKey = 'registro_horometro_id';

    protected $fillable = [
        'vehiculo_id',
        'valor_anterior',
        'valor_nuevo',
        'usuario_id',
        'notas',
    ];

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'vehiculo_id', 'vehiculo_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id', 'id');
    }
}
