<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{

    protected $table = 'empleados';
    protected $appends = ['name', 'nombre_completo'];

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
        return $this->hasMany(ChecklistPreoperacional::class, 'empleado_id', 'id');
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
