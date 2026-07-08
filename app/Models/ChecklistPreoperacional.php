<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChecklistPreoperacional extends Model
{
    use HasFactory;

    protected $table = 'checklists_preoperacionales';

    protected $fillable = [
        'vehiculo_id',
        'empleado_id',
        'fecha',
        'horometro_actual',
        'checklist_data',
        'observaciones',
        'estado',
        'foto_evidencia',
    ];

    protected $casts = [
        'checklist_data' => 'array',
        'fecha' => 'datetime',
        'horometro_actual' => 'decimal:2',
    ];

    public function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class, 'vehiculo_id', 'vehiculo_id');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }
}
