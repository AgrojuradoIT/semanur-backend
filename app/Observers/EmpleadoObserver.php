<?php

namespace App\Observers;

use App\Models\Empleado;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class EmpleadoObserver
{
    public function updated(Empleado $employee): void
    {
        if ($employee->wasChanged('estado') && $employee->estado !== 'activo') {
            $this->revokeTechnicalAccess($employee, 'status_changed');
        }
    }

    public function deleting(Empleado $employee): void
    {
        $this->revokeTechnicalAccess($employee, 'deleting');
    }

    private function revokeTechnicalAccess(Empleado $employee, string $reason): void
    {
        if ($employee->user_id === null) {
            return;
        }

        $user = User::query()->find($employee->user_id);

        if ($user === null) {
            return;
        }

        $user->tokens()->delete();
        $user->forceFill([
            'fcm_token' => null,
            'fcm_token_updated_at' => null,
        ])->saveQuietly();

        Log::notice('Employee technical access revoked', [
            'employee_id' => $employee->getKey(),
            'user_id' => $user->getKey(),
            'reason' => $reason,
        ]);
    }
}
