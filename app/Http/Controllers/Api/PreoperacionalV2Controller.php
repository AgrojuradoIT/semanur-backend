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
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PreoperacionalV2Controller extends Controller
{
    /**
     * GET /api/v2/preoperacionales/templates
     * Return active templates with sections and items nested.
     */
    public function templates(Request $request): JsonResponse
    {
        $query = PreoperacionalTemplate::with(['sections.items', 'items'])
            ->where('activo', true);

        if ($request->filled('tipo_vehiculo')) {
            $tipo = strtolower(trim($request->tipo_vehiculo));
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
        $fecha = $request->filled('fecha')
            ? Carbon::parse($request->fecha)
            : Carbon::today();

        $diaSemana = $this->dateToDiaSemana($fecha);
        $semanaInicio = $fecha->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        // Find all active vehicles
        $vehiculos = Vehiculo::whereNotNull('tipo')->get();

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
        $semanasIndex = $semanas->groupBy(fn($s) => $s->vehiculo_id . '-' . $s->template_id);

        $pendientes = [];

        foreach ($vehiculos as $vehiculo) {
            // Resolve template for this vehicle in memory
            $template = $templatesByTipo->get($vehiculo->tipo)?->first()
                ?? $templatesByTipo->get('generico')?->first();

            if (!$template) {
                continue;
            }

            // Find matching week record in memory
            $semana = $semanasIndex->get($vehiculo->vehiculo_id . '-' . $template->id)?->first();

            // Check if today's daily form is already completed in memory
            $dailyCompleted = false;
            if ($semana) {
                if (in_array($semana->id, $completedSemanaIds)) {
                    $dailyCompleted = true;
                }
            }

            if (!$dailyCompleted) {
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
            'vehiculo_id' => 'required|exists:vehiculos,vehiculo_id',
            'template_id' => 'nullable|exists:preoperacional_templates,id',
            'inspector_id' => 'required|exists:empleados,id',
            'semana_inicio' => 'required|date',
        ]);

        return DB::transaction(function () use ($validated) {
            $vehiculo = Vehiculo::where('vehiculo_id', $validated['vehiculo_id'])->firstOrFail();
            $inspector = Empleado::findOrFail($validated['inspector_id']);

            // Auto-resolve template if not provided
            $templateId = $validated['template_id'] ?? null;
            if (!$templateId) {
                $template = PreoperacionalTemplate::where('activo', true)
                    ->where('tipo_vehiculo', $vehiculo->tipo)
                    ->first();
                if (!$template) {
                    $template = PreoperacionalTemplate::where('activo', true)
                        ->where('tipo_vehiculo', 'generico')
                        ->first();
                }
                if (!$template) {
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
                    'data' => $this->formatSemana($existing->load(['dailyForms.responses.item', 'template.sections.items', 'template.items'])),
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
    public function showSemana(int $id): JsonResponse
    {
        $semana = PreoperacionalSemana::with([
            'dailyForms.responses.item',
            'template.sections.items',
            'template.items',
            'vehiculo',
            'inspector',
        ])->findOrFail($id);

        return response()->json($this->formatSemana($semana));
    }

    /**
     * POST /api/v2/preoperacionales/semanas/{semanaId}/dias/{diaSemana}
     * Submit daily form responses with server-side criticality evaluation.
     */
    public function submitDailyForm(int $semanaId, string $diaSemana, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'respuestas' => 'required|array',
            'respuestas.*.item_id' => 'required|exists:preoperacional_template_items,id',
            'respuestas.*.estado' => 'required|in:B,M,C,NC,N,A',
            'respuestas.*.observacion' => 'nullable|string',
            'respuestas.*.foto_url' => 'nullable|string',
            'observaciones_dia' => 'nullable|string',
        ]);

        $force = $request->boolean('force', false);

        return DB::transaction(function () use ($semanaId, $diaSemana, $validated, $force) {
            $semana = PreoperacionalSemana::findOrFail($semanaId);

            // Validate dia_semana is valid
            $validDias = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];
            if (!in_array($diaSemana, $validDias)) {
                return response()->json([
                    'message' => "Invalid dia_semana. Must be one of: " . implode(', ', $validDias),
                ], 422);
            }

            // Find or create daily form
            $dailyForm = PreoperacionalDailyForm::where('semana_id', $semanaId)
                ->where('dia_semana', $diaSemana)
                ->first();

            if (!$dailyForm) {
                return response()->json([
                    'message' => "Daily form for {$diaSemana} not found in this week.",
                ], 404);
            }

            // Deduplication check
            if ($dailyForm->completado && !$force) {
                return response()->json([
                    'message' => "Daily form for {$diaSemana} is already completed. Use ?force=true to override.",
                ], 409);
            }

            // Server-side criticality evaluation
            $hasCriticalFailure = false;
            $criticalItemIds = PreoperacionalTemplateItem::where('template_id', $semana->template_id)
                ->where('es_critico', true)
                ->pluck('id')
                ->toArray();

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
                        'foto_url' => $respuesta['foto_url'] ?? null,
                    ]
                );
            }

            // Auto-update semana estado
            $this->updateSemanaEstado($semana);

            return response()->json([
                'message' => 'Daily form submitted successfully.',
                'has_critical_failure' => $hasCriticalFailure,
                'data' => $dailyForm->load(['responses.item']),
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
            'motivo' => 'required|string|min:10',
        ]);

        return DB::transaction(function () use ($id, $validated) {
            $semana = PreoperacionalSemana::findOrFail($id);

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
        $query = PreoperacionalSemana::with([
            'dailyForms.responses.item',
            'template',
            'vehiculo',
            'inspector',
        ]);

        if ($request->filled('semana_anio')) {
            $query->where('semana_anio', $request->semana_anio);
        }

        if ($request->filled('semana_numero')) {
            $query->where('semana_numero', $request->semana_numero);
        }

        if ($request->filled('vehiculo_id')) {
            $query->where('vehiculo_id', $request->vehiculo_id);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
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
        if (!in_array($data['estado'], ['completado', 'fuera_servicio'])) {
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

        if ($semana->estado !== $newEstado && !$semana->fuera_de_servicio) {
            $semana->update(['estado' => $newEstado]);
        }
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
