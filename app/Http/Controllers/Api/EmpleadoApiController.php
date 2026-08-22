<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OffboardEmpleadoRequest;
use App\Http\Requests\StoreEmpleadoRequest;
use App\Http\Requests\UpdateEmpleadoRequest;
use App\Http\Resources\EmpleadoDetailResource;
use App\Http\Resources\EmpleadoOptionResource;
use App\Models\Empleado;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EmpleadoApiController extends Controller
{
    public function index(Request $request)
    {
        $canViewPersonalData = $request->user()->canAnyPermission([
            'personal.read',
            'personal.write',
        ]);

        $query = Empleado::query();

        if ($request->filled('q')) {
            $search = trim((string) $request->input('q'));
            $query->where(function ($builder) use ($search, $canViewPersonalData): void {
                $builder->where('nombres', 'like', "%{$search}%")
                    ->orWhere('apellidos', 'like', "%{$search}%");

                if ($canViewPersonalData) {
                    $builder->orWhere('documento', 'like', "%{$search}%");
                }
            });
        }

        if ($request->filled('estado') && $request->input('estado') !== 'todos') {
            $query->where('estado', $request->input('estado'));
        }

        $cargo = $request->input('cargo', $request->input('role'));
        if (! empty($cargo) && $cargo !== 'todos') {
            $query->where('cargo', $cargo);
        }

        $employees = $query->orderBy('nombres')->orderBy('apellidos')->get();
        $resource = $canViewPersonalData ? EmpleadoDetailResource::class : EmpleadoOptionResource::class;

        return response()->json(
            $employees->map(fn (Empleado $employee): array => $resource::make($employee)->resolve($request))
        );
    }

    public function store(StoreEmpleadoRequest $request)
    {
        $validated = $request->validated();

        $employee = DB::transaction(function () use ($request, $validated): Empleado {
            $userId = null;

            if ($request->boolean('crear_usuario')) {
                $user = User::create([
                    'name' => trim($validated['nombres'].' '.($validated['apellidos'] ?? '')),
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'role' => $validated['role'],
                    'phone' => $validated['telefono'] ?? null,
                    'license_number' => $validated['licencia_conduccion'] ?? null,
                    'cargo' => $validated['cargo'] ?? null,
                ]);
                $userId = $user->getKey();

                Log::notice('Technical user created with employee', [
                    'actor_user_id' => $request->user()->getKey(),
                    'created_user_id' => $userId,
                    'role' => $validated['role'],
                ]);
            }

            $employeeData = Arr::except($validated, ['crear_usuario', 'email', 'password', 'role', 'foto_url']);

            if ($request->hasFile('foto_url')) {
                $employeeData['foto_url'] = $request->file('foto_url')->store('empleados', 'public');
            }

            $employeeData['user_id'] = $userId;

            return Empleado::create($employeeData);
        });

        return response()->json(
            EmpleadoDetailResource::make($employee)->resolve($request),
            201,
        );
    }

    public function show(Request $request, int $empleado)
    {
        $employee = Empleado::with([
            'user:id,name,email,role,phone,license_number,cargo',
            'programaciones' => fn ($query) => $query->orderBy('fecha', 'desc'),
            'programaciones.vehiculo',
            'programaciones.ordenTrabajo',
            'ordenesTrabajoAsignadas' => fn ($query) => $query->orderBy('fecha_inicio', 'desc'),
            'ordenesTrabajoAsignadas.vehiculo',
            'registrosCombustible' => fn ($query) => $query->orderBy('fecha', 'desc'),
            'registrosCombustible.vehiculo',
            'prestamosHerramientas' => fn ($query) => $query->orderBy('fecha_prestamo', 'desc'),
            'prestamosHerramientas.producto',
            'checklists' => fn ($query) => $query->orderBy('fecha', 'desc'),
            'checklists.vehiculo',
        ])
            ->withCount([
                'ordenesTrabajoAsignadas',
                'prestamosHerramientas',
                'registrosCombustible',
                'checklists',
                'sesiones',
            ])
            ->findOrFail($empleado);

        $employee->setAttribute('stats', [
            'ot_asignadas' => $employee->ordenes_trabajo_asignadas_count,
            'prestamos' => $employee->prestamos_herramientas_count,
            'tanqueos' => $employee->registros_combustible_count,
            'checklists' => $employee->checklists_count,
            'sesiones' => $employee->sesiones_count,
        ]);

        return response()->json(
            EmpleadoDetailResource::make($employee)->resolve($request)
        );
    }

    public function update(UpdateEmpleadoRequest $request, int $empleado)
    {
        $employee = Empleado::findOrFail($empleado);
        $validated = $request->validated();

        DB::transaction(function () use ($request, $employee, $validated): void {
            $data = Arr::except($validated, ['foto_url']);

            if ($request->hasFile('foto_url')) {
                $previousPhoto = $employee->foto_url;
                $data['foto_url'] = $request->file('foto_url')->store('empleados', 'public');

                DB::afterCommit(function () use ($previousPhoto): void {
                    if ($previousPhoto && Storage::disk('public')->exists($previousPhoto)) {
                        Storage::disk('public')->delete($previousPhoto);
                    }
                });
            }

            $employee->update($data);
        });

        return response()->json(
            EmpleadoDetailResource::make($employee->refresh())->resolve($request)
        );
    }

    /**
     * Preserve the operational record and perform auditable offboarding
     * instead of deleting employee history.
     */
    public function destroy(OffboardEmpleadoRequest $request, int $empleado)
    {
        $employee = Empleado::findOrFail($empleado);
        $validated = $request->validated();

        DB::transaction(function () use ($employee, $validated, $request): void {
            $employee->update([
                'estado' => 'retirado',
                'fecha_retiro' => $validated['fecha_retiro'] ?? now()->toDateString(),
                'motivo_retiro' => $validated['motivo_retiro']
                    ?? $employee->motivo_retiro
                    ?? 'Retiro registrado mediante API',
            ]);

            Log::notice('Employee offboarded without deleting history', [
                'actor_user_id' => $request->user()->getKey(),
                'employee_id' => $employee->getKey(),
                'linked_user_id' => $employee->user_id,
            ]);
        });

        return response()->json([
            'message' => 'Empleado retirado; el historial fue preservado.',
            'data' => EmpleadoDetailResource::make($employee->refresh())->resolve($request),
        ]);
    }
}
