<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistroCombustible extends Model
{
    protected $table = 'registros_combustible';
    protected $primaryKey = 'registro_id';

    protected $fillable = [
        'vehiculo_id',
        'empleado_id',
        'tercero_nombre',
        'usuario_id',
        'fecha',
        'tipo_combustible',
        'cantidad_galones',
        'valor_total',
        'horometro_actual',
        'kilometraje_actual',
        'estacion_servicio',
        'tipo_destino',
        'placa_manual',
        'notas',
        'labor',
        'transaccion_id',
    ];

    protected $casts = [
        'fecha' => 'datetime',
        'cantidad_galones' => 'double',
        'valor_total' => 'double',
        'horometro_actual' => 'double',
        'kilometraje_actual' => 'double',
    ];

    public function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class, 'vehiculo_id', 'vehiculo_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id', 'id');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'empleado_id', 'id');
    }

    public function transaccion()
    {
        return $this->belongsTo(TransaccionInventario::class, 'transaccion_id', 'transaccion_id');
    }
}
