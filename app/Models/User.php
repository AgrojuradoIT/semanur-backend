<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Determina si el usuario puede acceder al panel de Filament.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin();
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'permisos',
        'phone',
        'license_number',
        'cargo',
        'fcm_token',
        'fcm_token_updated_at',
    ];

    protected $appends = ['permisos_efectivos'];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permisos' => 'array',
        ];
    }

    /**
     * Módulos disponibles en el sistema.
     */
    public static function modulos(): array
    {
        return [
            'combustible' => 'Combustible',
            'checklists' => 'Checklists',
            'inventario' => 'Inventario',
            'taller' => 'Taller (OT)',
            'flota' => 'Flota (Vehículos)',
            'personal' => 'Personal',
            'prestamos' => 'Préstamos',
            'movimientos' => 'Movimientos Inventario',
            'usuarios' => 'Usuarios',
            'media' => 'Archivos',
            'analitica' => 'Analítica',
            'notificaciones' => 'Notificaciones',
        ];
    }

    /**
     * Un admin siempre tiene acceso a todos los módulos.
     */
    public function isAdmin(): bool
    {
        return strtolower((string) $this->role) === 'admin';
    }

    /**
     * Determina si el usuario es jefe de taller.
     */
    public function isJefeDeTaller(): bool
    {
        return strtolower((string) $this->role) === 'jefe_taller';
    }

    /**
     * Determina si el usuario es auxiliar de bodega.
     */
    public function isAuxiliarBodega(): bool
    {
        return strtolower((string) $this->role) === 'auxiliar_bodega';
    }

    /**
     * Devuelve los permisos por defecto del rol del usuario (desde la DB).
     */
    public function roleDefaults(): array
    {
        if ($this->isAdmin()) {
            return array_keys(static::modulos());
        }

        $roleKey = strtolower((string) $this->role);

        return once(function () use ($roleKey) {
            $cached = \Illuminate\Support\Facades\Cache::remember(
                "role_permisos_{$roleKey}",
                now()->addHour(),
                fn () => RolePermiso::where('role', $roleKey)->value('permisos')
            );

            return $cached ?? [];
        });
    }

    /**
     * Permisos efectivos: unión de los defaults del rol + los específicos del usuario.
     */
    public function effectivePermissions(): array
    {
        if ($this->isAdmin()) {
            return array_keys(static::modulos());
        }

        $roleDefaults = $this->roleDefaults();
        $userPermisos = $this->permisos ?? [];

        return array_values(array_unique(array_merge($roleDefaults, $userPermisos)));
    }

    /**
     * Verifica si el usuario tiene acceso a un módulo específico (read o write).
     * Los admins siempre tienen acceso total.
     */
    public function canAccessModule(string $modulo): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $perms = $this->effectivePermissions();
        return in_array("{$modulo}.read", $perms, true) || in_array("{$modulo}.write", $perms, true);
    }

    /**
     * Verifica si el usuario tiene una acción específica de un módulo.
     * Ejemplo: hasPermission('taller.write')
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($permission, $this->effectivePermissions(), true);
    }

    /**
     * Verifica si el usuario tiene acceso a al menos uno de los módulos dados.
     */
    public function canAccessAnyModule(array $modulos): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $perms = $this->effectivePermissions();

        foreach ($modulos as $modulo) {
            // Check if they have ANY permission (read or write) on this module
            if (in_array("{$modulo}.read", $perms, true) || in_array("{$modulo}.write", $perms, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica si el usuario tiene al menos una de las acciones dadas.
     * Ejemplo: canAnyPermission(['taller.read', 'flota.read'])
     */
    public function canAnyPermission(array $permissions): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $perms = $this->effectivePermissions();

        foreach ($permissions as $permission) {
            if (in_array($permission, $perms, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Permisos adicionales del usuario (por encima de los de su rol).
     */
    public function permisosExtra(): array
    {
        if ($this->isAdmin()) {
            return [];
        }

        $roleDefaults = $this->roleDefaults();
        $userPermisos = $this->permisos ?? [];

        return array_values(array_diff($userPermisos, $roleDefaults));
    }

    /**
     * Módulos a los que el usuario tiene acceso (como etiquetas legibles).
     */
    public function permisosLabels(): array
    {
        if ($this->isAdmin()) {
            return ['Todos los módulos'];
        }

        $modulos = static::modulos();
        $labels = [];

        foreach ($this->effectivePermissions() as $p) {
            // Extraer el nombre del módulo (antes del punto)
            $moduleName = explode('.', $p)[0];
            $action = explode('.', $p)[1] ?? '';
            $actionLabel = $action === 'read' ? 'Lectura' : ($action === 'write' ? 'Escritura' : '');
            
            $moduleLabel = $modulos[$moduleName] ?? $moduleName;
            $fullLabel = trim("{$moduleLabel} {$actionLabel}");
            
            if (!in_array($fullLabel, $labels)) {
                $labels[] = $fullLabel;
            }
        }

        return $labels;
    }

    /**
     * Accessor: permisos efectivos (rol + usuario) para serialización JSON.
     */
    public function getPermisosEfectivosAttribute(): array
    {
        return $this->effectivePermissions();
    }
}
