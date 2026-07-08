<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehiculoDocumento extends Model
{
    protected $fillable = [
        'vehiculo_id',
        'tipo',
        'fecha_inicio',
        'fecha_vencimiento',
        'compania',
        'certificado_pdf',
        'estado',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_vencimiento' => 'date',
    ];

    public function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class, 'vehiculo_id', 'vehiculo_id');
    }

    protected static function booted()
    {
        static::saved(function ($documento) {
            $documento->syncVehicleDate();
        });

        static::deleted(function ($documento) {
            $documento->syncVehicleDate();
        });
    }

    public function syncVehicleDate()
    {
        $vehiculo = $this->vehiculo;
        if (!$vehiculo) return;

        // Buscar el documento más reciente del mismo tipo para este vehículo
        $ultimoDoc = self::where('vehiculo_id', $this->vehiculo_id)
            ->where('tipo', $this->tipo)
            ->orderBy('fecha_vencimiento', 'desc')
            ->first();

        $fecha = $ultimoDoc ? $ultimoDoc->fecha_vencimiento : null;

        if ($this->tipo === 'soat') {
            $vehiculo->update(['fecha_vencimiento_soat' => $fecha]);
        } elseif ($this->tipo === 'tecnomecanica') {
            $vehiculo->update(['fecha_vencimiento_tecnomecanica' => $fecha]);
        }
    }
}
