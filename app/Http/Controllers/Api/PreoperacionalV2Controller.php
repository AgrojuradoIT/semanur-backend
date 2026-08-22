<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\PreoperacionalDailyForm;
use App\Models\PreoperacionalFormResponse;
use App\Models\PreoperacionalSemana;
use App\Models\PreoperacionalTemplate;
use App\Models\PreoperacionalTemplateItem;
use App\Models\Vehiculo;
use App\Services\Authorization\OperationalAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class PreoperacionalV2Controller extends Controller
{
    public function __construct(
        private readonly OperationalAccess $access,
    ) {}

    /**
     * GET /api/v2/preoperacionales/templates
     * Return active templates with sections and items nested.
     */
    public function templates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo_vehiculo' => ['nullable', 'string', 'max:50'],
        ]);

        $query = PreoperacionalTemplate::with(['sections.items', 'items'])
            ->where('activo', true);

        if (isset($validated['tipo_vehiculo']) && $validated['tipo_vehiculo'] !== '') {
            $tipo = strtolower(trim($validated['tipo_vehiculo']));
            $templates = $query->where('tipo_vehiculo', $tipo)->get();

            // Fallback to 'generico' if no exact match
            if ($templates->isEmpty()) {
                $templates = PreoperacionalTemplate::with(['sections.items', 'items'])
                    ->where('activo', true)
                    ->where('tipo_vehiculo', 'generico')
                    ->get();
            }
        } else {
            $templates = $query->get();
        }

        return response()->json($templates->map(function ($template) {
            return $this->formatTemplate($template);
        }));
    }

    /**
     * GET /api/v2/preoperacionales/pendientes-hoy
     * Return vehicles that need a preoperacional today.
     */
    public function pendientesHoy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fecha' => ['nullable', 'date'],
        ]);

        $fecha = isset($validated['fecha'])
            ? Carbon::parse($validated['fecha'])
            : Carbon::today();

        $diaSemana = $this->dateToDiaSemana($fecha);
        $semanaInicio = $fecha->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        // Non-supervisors may only inspect the vehicle assigned to their
        // operational employee identity.
        $vehiclesQuery = Vehiculo::query()->whereNotNull('tipo');
        if (! $this->access->isSupervisor($request->user())) {
            $employeeId = $this->access->employeeId($request->user());
            $employeeId === null
                ? $vehiclesQuery->whereRaw('1 = 0')
                : $vehiclesQuery->where('operador_asignado_id', $employeeId);
        }
        $vehiculos = $vehiclesQuery->get();

        // Pre-load active templates
        $templatesByTipo = PreoperacionalTemplate::where('activo', true)->get()->groupBy('tipo_vehiculo');

        // Pre-load weeks matching the semanaInicio date
        $semanas = PreoperacionalSemana::whereDate('semana_inicio', $semanaInicio)->get();
        $semanaIds = $semanas->pluck('id')->toArray();

        // Pre-load completed daily forms
        $completedSemanaIds = PreoperacionalDailyForm::whereIn('semana_id', $semanaIds)
            ->where('dia_semana', $diaSemana)
            ->where('completado', true)
            ->pluck('semana_id')
            ->toArray();

        // Index semanas by vehicle_id and template_id for in-memory lookup
        $semanasIndex = $semanas->groupBy(fn ($s) => $s->vehiculo_id.'-'.$s->template_id);

        $pendientes = [];

        foreach ($vehiculos as $vehiculo) {
            // Resolve template for this vehicle in memory
            $template = $templatesByTipo->get($vehiculo->tipo)?->first()
                ?? $templatesByTipo->get('generico')?->first();

            if (! $template) {
                continue;
            }

            // Find matching week record in memory
            $semana = $semanasIndex->get($vehiculo->vehiculo_id.'-'.$template->id)?->first();

            // Check if today's daily form is already completed in memory
            $dailyCompleted = false;
            if ($semana) {
                if (in_array($semana->id, $completedSemanaIds)) {
                    $dailyCompleted = true;
                }
            }

            if (! $dailyCompleted) {
                $pendientes[] = [
                    'vehiculo_id' => $vehiculo->vehiculo_id,
                    'placa' => $vehiculo->placa,
                    'tipo' => $vehiculo->tipo,
                    'template_codigo' => $template->codigo,
                    'semana_id' => $semana?->id,
                    'dia_semana' => $diaSemana,
                ];
            }
        }

        return response()->json($pendientes);
    }

    /**
     * POST /api/v2/preoperacionales/semanas
     * Create a new weekly form instance with 7 daily forms.
     */
    public function storeSemana(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehiculo_id' => ['required', 'integer', 'exists:vehiculos,vehiculo_id'],
            'template_id' => [
                'nullable',
                'integer',
                Rule::exists('preoperacional_templates', 'id')->where('activo', true),
            ],
            'inspector_id' => [
                'required',
                'integer',
                Rule::exists('empleados', 'id')->where('estado', 'activo'),
            ],
            'semana_inicio' => ['required', 'date'],
        ]);

        $vehiculo = Vehiculo::where('vehiculo_id', $validated['vehiculo_id'])->firstOrFail();
        $inspector = Empleado::findOrFail($validated['inspector_id']);

        if (! $this->access->isSupervisor($request->user())) {
            $employeeId = $this->access->employeeId($request->user());
            abort_unless(
                $employeeId !== null
                    && $employeeId === (int) $inspector->getKey()
                    && $this->access->canUseVehicleForChecklist($request->user(), $vehiculo),
                403,
                'No autorizado para crear un preoperacional para otro inspector o vehículo.',
            );
        }

        return DB::transaction(function () use ($validated, $vehiculo, $inspector) {

            // Auto-resolve template if not provided
            $templateId = $validated['template_id'] ?? null;
            if (! $templateId) {
                $template = PreoperacionalTemplate::where('activo', true)
                    ->where('tipo_vehiculo', $vehiculo->tipo)
                    ->first();
                if (! $template) {
                    $template = PreoperacionalTemplate::where('activo', true)
                        ->where('tipo_vehiculo', 'generico')
                        ->first();
                }
                if (! $template) {
                    return response()->json([
                        'message' => 'No template found for vehicle type and no generic fallback available.',
                    ], 422);
                }
                $templateId = $template->id;
            }

            $semanaInicio = Carbon::parse($validated['semana_inicio']);
            $semanaFin = $semanaInicio->copy()->endOfWeek(Carbon::SUNDAY);
            $semanaNumero = $semanaInicio->isoWeek;
            $semanaAnio = $semanaInicio->year;

            // Check deduplication
            $existing = PreoperacionalSemana::where('vehiculo_id', $validated['vehiculo_id'])
                ->where('template_id', $templateId)
                ->whereDate('semana_inicio', $semanaInicio->format('Y-m-d'))
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'Weekly form already exists for this vehicle and week.',
                    'data' => $this->formatSemana($existing->load(['dailyForms.responses.item', 'dailyForms.responses.evidence', 'template.sections.items', 'template.items'])),
                ], 409);
            }

            $semana = PreoperacionalSemana::create([
                'vehiculo_id' => $validated['vehiculo_id'],
                'template_id' => $templateId,
                'inspector_id' => $validated['inspector_id'],
                'semana_inicio' => $semanaInicio->format('Y-m-d'),
                'semana_fin' => $semanaFin->format('Y-m-d'),
                'semana_numero' => $semanaNumero,
                'semana_anio' => $semanaAnio,
                'vehiculo_marca' => $vehiculo->marca,
                'vehiculo_modelo' => $vehiculo->modelo,
                'vehiculo_placa' => $vehiculo->placa,
                'inspector_nombre' => $inspector->nombre_completo,
                'inspector_cargo' => $inspector->cargo,
                'estado' => 'pendiente',
            ]);

            // Create 7 daily forms
            $dias = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];
            foreach ($dias as $index => $dia) {
                PreoperacionalDailyForm::create([
                    'semana_id' => $semana->id,
                    'dia_semana' => $dia,
                    'fecha' => $semanaInicio->copy()->addDays($index)->format('Y-m-d'),
                    'completado' => false,
                ]);
            }

            return response()->json([
                'message' => 'Weekly form created successfully.',
                'data' => $this->formatSemana($semana->load(['dailyForms', 'template.sections.items', 'template.items'])),
            ], 201);
        });
    }

    /**
     * GET /api/v2/preoperacionales/semanas/{id}
     * Return full weekly form with daily forms and responses.
     */
    public function showSemana(Request $request, int $id): JsonResponse
    {
        $semana = PreoperacionalSemana::with([
            'dailyForms.responses.item',
            'dailyForms.responses.evidence',
            'template.sections.items',
            'template.items',
            'vehiculo',
            'inspector',
        ])->findOrFail($id);

        abort_unless(
            $this->access->canAccessPreoperational($request->user(), $semana),
            403,
            'No autorizado para consultar este preoperacional.',
        );

        return response()->json($this->formatSemana($semana));
    }

    /**
     * POST /api/v2/preoperacionales/semanas/{semanaId}/dias/{diaSemana}
     * Submit daily form responses with server-side criticality evaluation.
     */
    public function submitDailyForm(int $semanaId, string $diaSemana, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_operation_id' => ['nullable', 'uuid'],
            'respuestas' => ['required', 'array', 'min:1', 'max:500'],
            'respuestas.*.item_id' => ['required', 'integer', 'distinct', 'exists:preoperacional_template_items,id'],
            'respuestas.*.estado' => ['required', Rule::in(['B', 'M', 'C', 'NC', 'N', 'A'])],
            'respuestas.*.observacion' => ['nullable', 'string', 'max:2000'],
            'respuestas.*.foto_url' => ['prohibited'],
            'respuestas.*.foto_path' => ['prohibited'],
            'observaciones_dia' => ['nullable', 'string', 'max:5000'],
        ]);

        $force = $request->boolean('force', false);

        return DB::transaction(function () use ($semanaId, $diaSemana, $validated, $force, $request) {
            $semana = PreoperacionalSemana::query()->lockForUpdate()->findOrFail($semanaId);

            abort_unless(
                $this->access->canAccessPreoperational($request->user(), $semana),
                403,
                'No autorizado para modificar este preoperacional.',
            );

            if ($force) {
                abort_unless(
                    $this->access->isSupervisor($request->user()),
                    403,
                    'Sólo un supervisor puede reemplazar un formulario completado.',
                );
            }

            // Validate dia_semana is valid
            $validDias = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];
            if (! in_array($diaSemana, $validDias)) {
                return response()->json([
                    'message' => 'Invalid dia_semana. Must be one of: '.implode(', ', $validDias),
                ], 422);
            }

            // Find or create daily form
            $dailyForm = PreoperacionalDailyForm::where('semana_id', $semanaId)
                ->where('dia_semana', $diaSemana)
                ->lockForUpdate()
                ->first();

            if (! $dailyForm) {
                return response()->json([
                    'message' => "Daily form for {$diaSemana} not found in this week.",
                ], 404);
            }

            // Deduplication check
            if ($dailyForm->completado && ! $force) {
                if (isset($validated['client_operation_id'])
                    && $this->matchesCompletedSubmission($dailyForm, $validated)) {
                    return response()->json([
                        'message' => "Daily form for {$diaSemana} was already completed; the original result was replayed.",
                        'client_operation_id' => strtolower($validated['client_operation_id']),
                        'replayed' => true,
                        'has_critical_failure' => $this->dailyFormHasCriticalFailure($dailyForm),
                        'data' => $dailyForm->load(['responses.item', 'responses.evidence']),
                    ]);
                }

                return response()->json([
                    'message' => "Daily form for {$diaSemana} is already completed. Use ?force=true to override.",
                ], 409);
            }

            // Server-side criticality evaluation
            $hasCriticalFailure = false;
            $templateItems = PreoperacionalTemplateItem::where('template_id', $semana->template_id)
                ->get(['id', 'es_critico']);
            $allowedItemIds = $templateItems->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $submittedItemIds = collect($validated['respuestas'])
                ->pluck('item_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if (array_diff($submittedItemIds, $allowedItemIds) !== []) {
                throw ValidationException::withMessages([
                    'respuestas' => ['Todas las respuestas deben pertenecer a la plantilla de esta semana.'],
                ]);
            }

            $criticalItemIds = $templateItems
                ->where('es_critico', true)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            foreach ($validated['respuestas'] as $respuesta) {
                if (in_array($respuesta['item_id'], $criticalItemIds)) {
                    if (in_array($respuesta['estado'], ['M', 'NC'])) {
                        $hasCriticalFailure = true;
                    }
                }
            }

            // Update daily form
            $dailyForm->update([
                'completado' => true,
                'observaciones_dia' => $validated['observaciones_dia'] ?? $dailyForm->observaciones_dia,
            ]);

            // Upsert responses
            foreach ($validated['respuestas'] as $respuesta) {
                PreoperacionalFormResponse::updateOrCreate(
                    [
                        'daily_form_id' => $dailyForm->id,
                        'item_id' => $respuesta['item_id'],
                    ],
                    [
                        'estado' => $respuesta['estado'],
                        'observacion' => $respuesta['observacion'] ?? null,
                    ]
                );
            }

            // Auto-update semana estado
            $this->updateSemanaEstado($semana);

            return response()->json([
                'message' => 'Daily form submitted successfully.',
                'client_operation_id' => isset($validated['client_operation_id'])
                    ? strtolower($validated['client_operation_id'])
                    : null,
                'replayed' => false,
                'has_critical_failure' => $hasCriticalFailure,
                'data' => $dailyForm->load(['responses.item', 'responses.evidence']),
            ]);
        });
    }

    /**
     * PUT /api/v2/preoperacionales/semanas/{id}/fuera-servicio
     * Mark weekly form as fuera_de_servicio.
     */
    public function markFueraServicio(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        abort_unless(
            $this->access->isSupervisor($request->user()),
            403,
            'Sólo un supervisor puede marcar un vehículo fuera de servicio.',
        );

        return DB::transaction(function () use ($id, $validated, $request) {
            $semana = PreoperacionalSemana::findOrFail($id);

            abort_unless(
                $this->access->canAccessPreoperational($request->user(), $semana),
                403,
                'No autorizado para modificar este preoperacional.',
            );

            $semana->update([
                'fuera_de_servicio' => true,
                'motivo_fuera_servicio' => $validated['motivo'],
                'estado' => 'fuera_servicio',
            ]);

            return response()->json([
                'message' => 'Weekly form marked as fuera de servicio.',
                'data' => $this->formatSemana($semana),
            ]);
        });
    }

    /**
     * GET /api/v2/preoperacionales/semanas
     * List weekly forms with filters.
     */
    public function indexSemanas(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'semana_anio' => ['nullable', 'integer', 'between:2000,2100'],
            'semana_numero' => ['nullable', 'integer', 'between:1,53'],
            'vehiculo_id' => ['nullable', 'integer', 'exists:vehiculos,vehiculo_id'],
            'estado' => ['nullable', Rule::in(['pendiente', 'en_progreso', 'completado', 'fuera_servicio'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = PreoperacionalSemana::with([
            'dailyForms.responses.item',
            'dailyForms.responses.evidence',
            'template',
            'vehiculo',
            'inspector',
        ]);

        if (! $this->access->isSupervisor($request->user())) {
            $employeeId = $this->access->employeeId($request->user());

            if ($employeeId === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function ($scope) use ($employeeId): void {
                    $scope->where('inspector_id', $employeeId)
                        ->orWhereHas('vehiculo', fn ($vehicles) => $vehicles->where('operador_asignado_id', $employeeId));
                });
            }
        }

        if (isset($validated['semana_anio'])) {
            $query->where('semana_anio', $validated['semana_anio']);
        }

        if (isset($validated['semana_numero'])) {
            $query->where('semana_numero', $validated['semana_numero']);
        }

        if (isset($validated['vehiculo_id'])) {
            $query->where('vehiculo_id', $validated['vehiculo_id']);
        }

        if (isset($validated['estado'])) {
            $query->where('estado', $validated['estado']);
        }

        $semanas = $query->orderBy('semana_inicio', 'desc')->paginate(20);

        $semanas->getCollection()->transform(function ($semana) {
            return $this->formatSemana($semana);
        });

        return response()->json($semanas);
    }

    // ─── Private Helpers ───────────────────────────────────

    /**
     * Format a template with nested sections and items.
     */
    private function formatTemplate(PreoperacionalTemplate $template): array
    {
        $sections = $template->sections->map(function ($section) {
            return [
                'id' => $section->id,
                'nombre' => $section->nombre,
                'descripcion' => $section->descripcion,
                'orden' => $section->orden,
                'items' => $section->items->map(function ($item) {
                    return $this->formatItem($item);
                })->toArray(),
            ];
        })->toArray();

        // Items without section (section_id = null)
        $flatItems = $template->items
            ->whereNull('section_id')
            ->map(function ($item) {
                return $this->formatItem($item);
            })->toArray();

        return [
            'id' => $template->id,
            'codigo' => $template->codigo,
            'nombre' => $template->nombre,
            'tipo_vehiculo' => $template->tipo_vehiculo,
            'descripcion' => $template->descripcion,
            'escala_predeterminada' => $template->escala_predeterminada,
            'requiere_conductor' => $template->requiere_conductor,
            'requiere_documentos_vehiculo' => $template->requiere_documentos_vehiculo,
            'requiere_aprobacion' => $template->requiere_aprobacion,
            'sections' => $sections,
            'items' => $flatItems,
        ];
    }

    /**
     * Format a single template item.
     */
    private function formatItem(PreoperacionalTemplateItem $item): array
    {
        return [
            'id' => $item->id,
            'codigo' => $item->codigo,
            'pregunta' => $item->pregunta,
            'tipo_respuesta' => $item->tipo_respuesta,
            'escala_valores' => $item->escala_valores,
            'es_critico' => $item->es_critico,
            'requiere_observacion_si_falla' => $item->requiere_observacion_si_falla,
            'orden' => $item->orden,
        ];
    }

    /**
     * Format a semana with all relations.
     */
    private function formatSemana(PreoperacionalSemana $semana): array
    {
        $data = $semana->toArray();

        // Calcular dinámicamente si la semana está vencida
        if (! in_array($data['estado'], ['completado', 'fuera_servicio'])) {
            if ($semana->semana_fin && $semana->semana_fin->lt(Carbon::today())) {
                $data['estado'] = 'vencida';
            }
        }

        $data['daily_forms'] = $semana->dailyForms->map(function ($dailyForm) {
            $form = $dailyForm->toArray();
            if ($dailyForm->relationLoaded('responses')) {
                $form['responses'] = $dailyForm->responses->map(function ($response) {
                    $r = $response->toArray();
                    if ($response->relationLoaded('item')) {
                        $r['item'] = $this->formatItem($response->item);
                    }

                    return $r;
                })->toArray();
            }

            return $form;
        })->toArray();

        if ($semana->relationLoaded('template')) {
            $data['template'] = $this->formatTemplate($semana->template);
        }

        return $data;
    }

    /**
     * Auto-update semana estado based on daily forms completion.
     */
    private function updateSemanaEstado(PreoperacionalSemana $semana): void
    {
        $totalDays = 7;
        $completedDays = $semana->dailyForms()->where('completado', true)->count();

        if ($completedDays === 0) {
            $newEstado = 'pendiente';
        } elseif ($completedDays === $totalDays) {
            $newEstado = 'completado';
        } else {
            $newEstado = 'en_progreso';
        }

        if ($semana->estado !== $newEstado && ! $semana->fuera_de_servicio) {
            $semana->update(['estado' => $newEstado]);
        }
    }

    /** @param array<string, mixed> $validated */
    private function matchesCompletedSubmission(
        PreoperacionalDailyForm $dailyForm,
        array $validated,
    ): bool {
        $submitted = collect($validated['respuestas'])
            ->map(fn (array $response): array => [
                'item_id' => (int) $response['item_id'],
                'estado' => (string) $response['estado'],
                'observacion' => isset($response['observacion'])
                    ? (string) $response['observacion']
                    : null,
            ])
            ->sortBy('item_id')
            ->values()
            ->all();

        $stored = $dailyForm->responses()
            ->get(['item_id', 'estado', 'observacion'])
            ->map(fn (PreoperacionalFormResponse $response): array => [
                'item_id' => (int) $response->item_id,
                'estado' => (string) $response->estado,
                'observacion' => $response->observacion === null
                    ? null
                    : (string) $response->observacion,
            ])
            ->sortBy('item_id')
            ->values()
            ->all();

        $submittedNotes = $validated['observaciones_dia'] ?? null;
        $storedNotes = $dailyForm->observaciones_dia;

        return $submitted === $stored
            && ($submittedNotes === null ? null : (string) $submittedNotes)
                === ($storedNotes === null ? null : (string) $storedNotes);
    }

    private function dailyFormHasCriticalFailure(PreoperacionalDailyForm $dailyForm): bool
    {
        return $dailyForm->responses()
            ->whereIn('estado', ['M', 'NC'])
            ->whereHas('item', fn ($items) => $items->where('es_critico', true))
            ->exists();
    }

    /**
     * Convert a Carbon date to dia_semana enum value.
     */
    private function dateToDiaSemana(Carbon $date): string
    {
        $map = [
            1 => 'lunes',
            2 => 'martes',
            3 => 'miercoles',
            4 => 'jueves',
            5 => 'viernes',
            6 => 'sabado',
            7 => 'domingo',
        ];

        // Carbon's dayOfWeekIso: 1=Monday, ..., 7=Sunday
        $isoDay = $date->dayOfWeekIso;

        return $map[$isoDay] ?? 'lunes';
    }
}
