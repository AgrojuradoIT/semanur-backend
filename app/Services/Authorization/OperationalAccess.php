<?php

namespace App\Services\Authorization;

use App\Models\Empleado;
use App\Models\Novedad;
use App\Models\OrdenTrabajo;
use App\Models\PreoperacionalSemana;
use App\Models\User;
use App\Models\Vehiculo;

final class OperationalAccess
{
    public function employeeId(User $user): ?int
    {
        $employeeId = Empleado::query()
            ->where('user_id', $user->getKey())
            ->where('estado', 'activo')
            ->value('id');

        return $employeeId === null ? null : (int) $employeeId;
    }

    public function isSupervisor(User $user): bool
    {
        return in_array(strtolower((string) $user->role), ['admin', 'jefe_taller'], true);
    }

    public function canUseVehicleForChecklist(User $user, Vehiculo $vehicle): bool
    {
        if ($this->isSupervisor($user)) {
            return true;
        }

        $employeeId = $this->employeeId($user);

        return $employeeId !== null
            && (int) $vehicle->operador_asignado_id === $employeeId;
    }

    public function canAccessPreoperational(User $user, PreoperacionalSemana $week): bool
    {
        if ($this->isSupervisor($user)) {
            return true;
        }

        $employeeId = $this->employeeId($user);

        if ($employeeId === null) {
            return false;
        }

        if ((int) $week->inspector_id === $employeeId) {
            return true;
        }

        $week->loadMissing('vehiculo');

        return (int) $week->vehiculo?->operador_asignado_id === $employeeId;
    }

    public function canAccessWorkOrder(User $user, OrdenTrabajo $workOrder): bool
    {
        if (in_array(strtolower((string) $user->role), ['admin', 'jefe_taller', 'auxiliar_bodega'], true)) {
            return true;
        }

        $employeeId = $this->employeeId($user);

        return $employeeId !== null
            && (int) $workOrder->mecanico_asignado_id === $employeeId;
    }

    public function canAccessIncident(User $user, Novedad $incident): bool
    {
        if ($this->isSupervisor($user)) {
            return true;
        }

        $employeeId = $this->employeeId($user);

        return $employeeId !== null
            && (int) $incident->empleado_id === $employeeId;
    }
}
