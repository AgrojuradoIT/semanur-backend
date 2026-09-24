<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Empleado extends Model
{

    protected $table = 'empleados';
    protected $appends = ['name', 'nombre_completo'];

    /**
     * Employee records are embedded in many operational API responses. Keep
     * personal data hidden by default and expose it only through the
     * permission-gated EmpleadoDetailResource.
     */
    protected $hidden = [
        'documento',
        'telefono',
        'direccion',
        'licencia_conduccion',
        'categoria_licencia',
        'vencimiento_licencia',
        'user_id',
        'resumen_profesional',
        'fecha_retiro',
        'motivo_retiro',
    ];

    protected $fillable = [
        'nombres',
        'apellidos',
        'documento',
        'telefono',
        'direccion',
        'cargo',
        'licencia_conduccion',
        'categoria_licencia',
        'vencimiento_licencia',
        'foto_url',
        'user_id',
        'resumen_profesional',
        'estado',
        'fecha_retiro',
        'motivo_retiro',
    ];

    protected $casts = [
        'fecha_retiro' => 'date',
        'vencimiento_licencia' => 'date',
    ];

    // ─── Relaciones ────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ordenesTrabajoAsignadas()
    {
        return $this->hasMany(OrdenTrabajo::class, 'mecanico_asignado_id', 'id');
    }

    public function prestamosHerramientas()
    {
        return $this->hasMany(PrestamoHerramienta::class, 'mecanico_id', 'id');
    }

    public function registrosCombustible()
    {
        return $this->hasMany(RegistroCombustible::class, 'empleado_id', 'id');
    }

    public function checklists()
    {
        if (Schema::hasColumn('checklists_preoperacionales', 'empleado_id')) {
            return $this->hasMany(ChecklistPreoperacional::class, 'empleado_id', 'id');
        }

        // Compatibility for installations where the historical employee FK
        // migration has not yet replaced usuario_id.
        return $this->hasMany(ChecklistPreoperacional::class, 'usuario_id', 'user_id');
    }

    public function respuestasListaChequeo()
    {
        return $this->hasMany(RespuestaListaChequeo::class, 'operador_id', 'id');
    }

    public function sesiones()
    {
        return $this->hasMany(WorkSession::class, 'empleado_id', 'id');
    }

    public function programaciones()
    {
        return $this->hasMany(Programacion::class, 'empleado_id', 'id');
    }

    // ─── Accessors ─────────────────────────────────────

    public function getNameAttribute(): string
    {
        return trim(($this->nombres ?? '') . ' ' . ($this->apellidos ?? ''));
    }

    public function getNombreCompletoAttribute(): string
    {
        return $this->getNameAttribute();
    }
}
