<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmpleadoApiController extends Controller
{
    public function index(Request $request)
    {
        $query = Empleado::query();

        if ($request->has('q')) {
            $search = $request->q;
            $query->where(function($q) use ($search) {
                $q->where('nombres', 'like', "%{$search}%")
                  ->orWhere('apellidos', 'like', "%{$search}%")
                  ->orWhere('documento', 'like', "%{$search}%");
            });
        }

        // Filtro por estado
        if ($request->has('estado') && $request->estado !== 'todos') {
            $query->where('estado', $request->estado);
        }

        // Compatibilidad: frontend puede enviar 'role' o 'cargo'
        $cargo = $request->input('cargo', $request->input('role'));
        if (!empty($cargo) && $cargo !== 'todos') {
            $query->where('cargo', $cargo);
        }

        return response()->json(
            $query
                ->select([
                    'id',
                    'nombres',
                    'apellidos',
                    'documento',
                    'telefono',
                    'cargo',
                    'licencia_conduccion',
                    'categoria_licencia',
                    'estado',
                    'foto_url',
                    'fecha_retiro',
                    'motivo_retiro',
                    'user_id',
                    'created_at',
                    'updated_at',
                ])
                ->orderBy('nombres')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombres' => 'required|string|max:255',
            'apellidos' => 'nullable|string|max:255',
            'documento' => 'nullable|string|max:50|unique:empleados',
            'telefono' => 'nullable|string|max:50',
            'direccion' => 'nullable|string|max:255',
            'cargo' => ['nullable', 'string', 'max:100', Rule::exists('cargos', 'nombre')->where('activo', true)],
            'licencia_conduccion' => 'nullable|string|max:50',
            'categoria_licencia' => 'nullable|string|max:10',
            'vencimiento_licencia' => 'nullable|date',
            'resumen_profesional' => 'nullable|string|max:5000',
            'foto_url' => 'nullable|image|max:5120',
            // User validation if creating user
            'crear_usuario' => 'boolean',
            'email' => 'required_if:crear_usuario,true|email|unique:users,email',
            'password' => 'required_if:crear_usuario,true|min:6',
            'role' => 'required_if:crear_usuario,true|in:admin,mecanico,operador,almacenista',
        ]);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            $userId = null;

            if ($request->boolean('crear_usuario')) {
                $user = \App\Models\User::create([
                    'name' => $request->nombres . ' ' . $request->apellidos,
                    'email' => $request->email,
                    'password' => \Illuminate\Support\Facades\Hash::make($request->password),
                    'role' => $request->role,
                    'phone' => $request->telefono,
                    'license_number' => $request->licencia_conduccion,
                    'cargo' => $request->cargo,
                ]);
                $userId = $user->id;
            }

            $empleadoData = $request->except(['crear_usuario', 'email', 'password', 'role']);
            
            if ($request->hasFile('foto_url')) {
                $path = $request->file('foto_url')->store('empleados', 'public');
                $empleadoData['foto_url'] = $path;
            }

            $empleadoData['user_id'] = $userId;

            $empleado = Empleado::create($empleadoData);

            return response()->json($empleado, 201);
        });
    }

    public function show($id)
    {
        $empleado = Empleado::with([
                'user',
                'programaciones' => function ($q) {
                    $q->orderBy('fecha', 'desc');
                },
                'programaciones.vehiculo',
                'programaciones.ordenTrabajo',
                'ordenesTrabajoAsignadas' => function ($q) {
                    $q->orderBy('fecha_inicio', 'desc');
                },
                'ordenesTrabajoAsignadas.vehiculo',
                'registrosCombustible' => function ($q) {
                    $q->orderBy('fecha', 'desc');
                },
                'registrosCombustible.vehiculo',
                'prestamosHerramientas' => function ($q) {
                    $q->orderBy('fecha_prestamo', 'desc');
                },
                'prestamosHerramientas.producto',
                'checklists' => function ($q) {
                    $q->orderBy('fecha', 'desc');
                },
                'checklists.vehiculo'
            ])
            ->withCount([
                'ordenesTrabajoAsignadas',
                'prestamosHerramientas',
                'registrosCombustible',
                'checklists',
                'sesiones'
            ])
            ->findOrFail($id);

        // Contar estadísticas reales
        $stats = [
            'ot_asignadas'   => $empleado->ordenes_trabajo_asignadas_count,
            'prestamos'      => $empleado->prestamos_herramientas_count,
            'tanqueos'       => $empleado->registros_combustible_count,
            'checklists'     => $empleado->checklists_count,
            'sesiones'       => $empleado->sesiones_count,
        ];

        $data = $empleado->toArray();
        $data['stats'] = $stats;

        return response()->json($data);
    }

    public function update(Request $request, $id)
    {
        $empleado = Empleado::findOrFail($id);

        $request->validate([
            'nombres' => 'sometimes|required|string|max:255',
            'apellidos' => 'nullable|string|max:255',
            'documento' => ['nullable', 'string', 'max:50', Rule::unique('empleados')->ignore($empleado->id)],
            'telefono' => 'nullable|string|max:50',
            'direccion' => 'nullable|string|max:255',
            'cargo' => ['nullable', 'string', 'max:100', Rule::exists('cargos', 'nombre')->where('activo', true)],
            'licencia_conduccion' => 'nullable|string|max:50',
            'categoria_licencia' => 'nullable|string|max:10',
            'vencimiento_licencia' => 'nullable|date',
            'resumen_profesional' => 'nullable|string|max:5000',
            'foto_url' => 'nullable|image|max:5120',
            'estado' => 'nullable|string|in:activo,inactivo,retirado',
            'fecha_retiro' => 'nullable|date',
            'motivo_retiro' => 'nullable|string|max:500',
            'user_id' => ['nullable', 'exists:users,id', Rule::unique('empleados')->ignore($empleado->id)],
        ]);

        $data = $request->all();
        if ($request->hasFile('foto_url')) {
            if ($empleado->foto_url && \Illuminate\Support\Facades\Storage::disk('public')->exists($empleado->foto_url)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($empleado->foto_url);
            }
            $path = $request->file('foto_url')->store('empleados', 'public');
            $data['foto_url'] = $path;
        }

        $empleado->update($data);

        return response()->json($empleado);
    }

    public function destroy($id)
    {
        $empleado = Empleado::findOrFail($id);
        $empleado->delete();

        return response()->json(['message' => 'Empleado eliminado correctamente']);
    }
}
