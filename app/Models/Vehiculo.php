<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehiculo extends Model
{
    protected $primaryKey = 'vehiculo_id';

    protected $fillable = [
        'placa',
        'tipo',
        'categoria',
        'tipo_combustible',
        'metodo_seguimiento',
        'marca',
        'modelo',
        'imagen_url',
        'imagen_thumb_url',
        'horometro_actual',
        'horometro_proximo_mantenimiento',
        'kilometraje_actual',
        'kilometraje_proximo_mantenimiento',
        'fecha_vencimiento_soat',
        'fecha_vencimiento_tecnomecanica',
        'operador_asignado_id',
        'mecanico_asignado_id',
    ];

    protected $casts = [
        'fecha_vencimiento_soat' => 'date',
        'fecha_vencimiento_tecnomecanica' => 'date',
    ];

    public function ordenesTrabajo(): HasMany
    {
        return $this->hasMany(OrdenTrabajo::class, 'vehiculo_id', 'vehiculo_id');
    }

    public function registrosHorometro(): HasMany
    {
        return $this->hasMany(RegistroHorometro::class, 'vehiculo_id', 'vehiculo_id');
    }

    public function movimientosDirectos(): HasMany
    {
        return $this->hasMany(TransaccionInventario::class, 'transaccion_referencia_id', 'vehiculo_id')
                    ->where('transaccion_referencia_type', 'Vehiculo');
    }

    public function operador()
    {
        return $this->belongsTo(Empleado::class, 'operador_asignado_id', 'id');
    }

    public function mecanico()
    {
        return $this->belongsTo(Empleado::class, 'mecanico_asignado_id', 'id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(VehiculoDocumento::class, 'vehiculo_id', 'vehiculo_id');
    }

    public function respuestasChecklist(): HasMany
    {
        return $this->hasMany(RespuestaListaChequeo::class, 'vehiculo_id', 'vehiculo_id');
    }

    /**
     * Mutador para formatear y normalizar el tipo de vehículo al guardar en BD.
     * Convierte a minúsculas y elimina tildes/acentos.
     * Ejemplo: "Camión" -> "camion"
     */
    public function setTipoAttribute($value)
    {
        if (empty($value)) {
            $this->attributes['tipo'] = $value;
            return;
        }

        // 1. Convertir a minúsculas (usando mb_strtolower para soporte de caracteres especiales)
        $min = mb_strtolower($value, 'UTF-8');

        // 2. Reemplazar tildes y caracteres especiales con sus equivalentes normales
        $unwanted_array = [
            'á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u',
            'à'=>'a', 'è'=>'e', 'ì'=>'i', 'ò'=>'o', 'ù'=>'u',
            'ä'=>'a', 'ë'=>'e', 'ï'=>'i', 'ö'=>'o', 'ü'=>'u',
            'ñ'=>'n'
        ];
        
        $normalized = strtr($min, $unwanted_array);

        // Guardar el valor limpio
        $this->attributes['tipo'] = trim($normalized);
    }
}
