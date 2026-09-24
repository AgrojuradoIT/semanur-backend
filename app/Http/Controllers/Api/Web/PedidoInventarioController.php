<?php

namespace App\Http\Controllers\Api\Web;

use App\Domain\InventoryOrders\OperacionRechazadaException;
use App\Domain\InventoryOrders\PedidoEstado;
use App\Domain\InventoryOrders\VersionDesactualizadaException;
use App\DTO\InventoryOrders\DecimalQuantity;
use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryOrders\CancelPedidoInventarioRequest;
use App\Http\Requests\InventoryOrders\PublishPedidoInventarioRequest;
use App\Http\Requests\InventoryOrders\ReturnPedidoInventarioRequest;
use App\Http\Requests\InventoryOrders\StorePedidoInventarioRequest;
use App\Http\Requests\InventoryOrders\UpdatePedidoInventarioRequest;
use App\Http\Resources\InventoryOrders\PedidoEventoResource;
use App\Http\Resources\InventoryOrders\PedidoInventarioResource;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoOperacion;
use App\Models\Producto;
use App\Models\User;
use App\Services\Inventory\Orders\PedidoPublicacionService;
use App\Services\Inventory\Orders\PedidoRetiroCancelacionService;
use App\Services\Inventory\Orders\PedidoSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Borradores de pedidos de inventario para la Web (contabilidad).
 *
 * Alcance U2: creación idempotente, listado, detalle y edición de borrador con
 * versión optimista. No hay documentos, OCR, publicación, cancelación ni
 * recepción en esta unidad; esos flujos viven en U3-U6.
 *
 * Autorización: la matriz de la sección 7 vive en PedidoInventarioPolicy y se
 * evalúa vía Gate. No se usa una allowlist de rol en la ruta para no bloquear
 * habilitaciones explícitas por configuración (por ejemplo supervisor).
 */
class PedidoInventarioController extends Controller
{
    private const COMMAND_CREATE = 'crear_borrador';

    private const COMMAND_UPDATE = 'actualizar_borrador';

    /**
     * Comandos con operación idempotente propia que la UI puede recuperar por
     * actor+pedido tras una respuesta perdida (plan sección 17). El comando
     * forma parte de la clave idempotente: se valida contra esta allowlist y
     * además contra `actor_id` y `referencia` del pedido.
     *
     * @var list<string>
     */
    private const COMMANDS_RECUPERABLES = [
        self::COMMAND_CREATE,
        self::COMMAND_UPDATE,
        'publicar_pedido',
        'retirar_publicacion',
        'cancelar_pedido',
        'confirmar_recepcion',
        'responder_novedad',
        'autorizar_desfase',
    ];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PedidoInventario::class);

        $validated = $request->validate([
            'estado' => ['nullable', Rule::in(PedidoEstado::todos())],
            'proveedor' => ['nullable', 'string', 'max:255'],
            'bodega_id' => ['nullable', 'integer', 'exists:bodegas,bodega_id'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = PedidoInventario::query()
            ->with(['bodega:bodega_id,nombre,tipo'])
            ->withCount('lineas')
            ->orderByDesc('pedido_id');

        if (! empty($validated['estado'])) {
            $query->where('estado', $validated['estado']);
        }

        if (! empty($validated['proveedor'])) {
            $proveedor = (string) $validated['proveedor'];
            $query->where(function ($builder) use ($proveedor): void {
                $builder->where('proveedor_nombre_raw', 'like', "%{$proveedor}%")
                    ->orWhere('proveedor_nombre_confirmado', 'like', "%{$proveedor}%");
            });
        }

        if (! empty($validated['bodega_id'])) {
            $query->where('bodega_id', (int) $validated['bodega_id']);
        }

        if (! empty($validated['desde'])) {
            $query->whereDate('created_at', '>=', $validated['desde']);
        }

        if (! empty($validated['hasta'])) {
            $query->whereDate('created_at', '<=', $validated['hasta']);
        }

        $paginator = $query->paginate($validated['per_page'] ?? 25);
        $data = PedidoInventarioResource::collection($paginator->getCollection())->resolve($request);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StorePedidoInventarioRequest $request): JsonResponse
    {
        Gate::authorize('create', PedidoInventario::class);

        $validated = $request->validated();
        $actorId = (int) $request->user()->id;
        $operationId = (string) $validated['client_operation_id'];
        $hash = $this->operationHash($validated);

        if ($existing = $this->findOperation($actorId, self::COMMAND_CREATE, $operationId)) {
            return $this->replayResponse($existing, $hash);
        }

        try {
            $result = DB::transaction(function () use ($request, $validated, $actorId, $operationId): array {
                if ($existing = $this->findOperation($actorId, self::COMMAND_CREATE, $operationId, true)) {
                    return ['operacion' => $existing, 'replay' => true, 'payload' => null];
                }

                $pedido = PedidoInventario::create([
                    'proveedor_nombre_raw' => $validated['proveedor_nombre_raw'] ?? null,
                    'referencia_factura' => $validated['referencia_factura'] ?? null,
                    'bodega_id' => (int) $validated['bodega_id'],
                    'estado' => PedidoEstado::BORRADOR,
                    'created_by' => $actorId,
                ]);

                $this->reemplazarLineas($pedido, $validated['lineas']);

                $pedido->load(['bodega', 'lineas']);
                $payload = (new PedidoInventarioResource($pedido))->resolve($request);

                $operacion = PedidoOperacion::create([
                    'actor_id' => $actorId,
                    'command' => self::COMMAND_CREATE,
                    'client_operation_id' => $operationId,
                    'canonical_request_hash' => $this->operationHash($validated),
                    'estado' => 'completada',
                    'referencia' => $pedido->uuid,
                    'resultado' => $payload,
                    'completada_at' => now(),
                ]);

                $this->registrarEvento(
                    $pedido,
                    $request->user(),
                    'borrador_creado',
                    null,
                    $this->pedidoSnapshot($pedido),
                    $operationId,
                );

                return ['operacion' => $operacion, 'replay' => false, 'payload' => $payload];
            });
        } catch (QueryException $exception) {
            $existing = $this->findOperation($actorId, self::COMMAND_CREATE, $operationId);

            if ($existing === null) {
                throw $exception;
            }

            return $this->replayResponse($existing, $hash);
        }

        if ($result['replay']) {
            return $this->replayResponse($result['operacion'], $hash);
        }

        return $this->mutationResponse($result['payload'], false, 201);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        $pedido = $this->findPedido($order)->load([
            'bodega',
            'lineas',
            'documentos',
            'novedades',
            'ultimaRecepcionConfirmada',
        ]);

        Gate::authorize('view', $pedido);

        return response()->json([
            'success' => true,
            'data' => (new PedidoInventarioResource($pedido))->resolve($request),
        ]);
    }

    public function update(UpdatePedidoInventarioRequest $request, string $order): JsonResponse
    {
        $pedido = $this->findPedido($order);

        Gate::authorize('update', $pedido);

        $validated = $request->validated();
        $actorId = (int) $request->user()->id;
        $operationId = (string) $validated['client_operation_id'];
        $hash = $this->operationHash($validated, $pedido->uuid);

        if ($existing = $this->findOperation($actorId, self::COMMAND_UPDATE, $operationId)) {
            return $this->replayResponse($existing, $hash);
        }

        try {
            $result = DB::transaction(function () use ($request, $pedido, $validated, $actorId, $operationId, $hash): array {
                if ($existing = $this->findOperation($actorId, self::COMMAND_UPDATE, $operationId, true)) {
                    return ['operacion' => $existing, 'replay' => true, 'payload' => null];
                }

                $pedido = PedidoInventario::query()
                    ->whereKey($pedido->pedido_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $pedido->estaEnBorrador()) {
                    abort(409, 'El pedido ya no está en borrador y no admite esta edición.');
                }

                $pedido->assertVersion((int) $validated['version']);

                $pedido->load('lineas');
                $antes = $this->pedidoSnapshot($pedido);

                if (array_key_exists('proveedor_nombre_raw', $validated)) {
                    $pedido->proveedor_nombre_raw = $validated['proveedor_nombre_raw'];
                }

                if (array_key_exists('proveedor_nombre_confirmado', $validated)) {
                    $pedido->proveedor_nombre_confirmado = $validated['proveedor_nombre_confirmado'];
                }

                if (array_key_exists('proveedor_clave', $validated)) {
                    $pedido->proveedor_clave = $validated['proveedor_clave'];
                }

                if (array_key_exists('referencia_factura', $validated)) {
                    $pedido->referencia_factura = $validated['referencia_factura'];
                }

                if (array_key_exists('bodega_id', $validated) && $validated['bodega_id'] !== null) {
                    $pedido->bodega_id = (int) $validated['bodega_id'];
                }

                $pedido->incrementarVersion();
                $pedido->save();

                if (array_key_exists('lineas', $validated)) {
                    $this->reemplazarLineas($pedido, $validated['lineas']);
                }

                $pedido->load(['bodega', 'lineas']);
                $payload = (new PedidoInventarioResource($pedido))->resolve($request);

                $operacion = PedidoOperacion::create([
                    'actor_id' => $actorId,
                    'command' => self::COMMAND_UPDATE,
                    'client_operation_id' => $operationId,
                    'canonical_request_hash' => $hash,
                    'estado' => 'completada',
                    'referencia' => $pedido->uuid,
                    'resultado' => $payload,
                    'completada_at' => now(),
                ]);

                $this->registrarEvento(
                    $pedido,
                    $request->user(),
                    'borrador_actualizado',
                    $antes,
                    $this->pedidoSnapshot($pedido),
                    $operationId,
                );

                return ['operacion' => $operacion, 'replay' => false, 'payload' => $payload];
            });
        } catch (VersionDesactualizadaException) {
            return response()->json([
                'message' => 'La versión enviada no coincide con la versión vigente del pedido.',
                'current_version' => (int) PedidoInventario::query()
                    ->where('uuid', $pedido->uuid)
                    ->value('version'),
            ], 409);
        } catch (QueryException $exception) {
            $existing = $this->findOperation($actorId, self::COMMAND_UPDATE, $operationId);

            if ($existing === null) {
                throw $exception;
            }

            return $this->replayResponse($existing, $hash);
        }

        if ($result['replay']) {
            return $this->replayResponse($result['operacion'], $hash);
        }

        return $this->mutationResponse($result['payload'], false, 200);
    }

    /**
     * Publica el pedido revisado (R02/R03/R17). Las puertas de datos responden
     * 422 con mensaje propio; la falta de receptor y la colisión documental
     * responden 409. La capacidad y el estado borrador los garantiza la Policy.
     */
    public function publish(
        PublishPedidoInventarioRequest $request,
        string $order,
        PedidoPublicacionService $publicacion,
    ): JsonResponse {
        $pedido = $this->findPedido($order);

        Gate::authorize('publishCapability', $pedido);

        $validated = $request->validated();

        try {
            $resultado = $publicacion->publicar(
                $pedido,
                $request->user(),
                (int) $validated['version'],
                (string) $validated['client_operation_id'],
                $validated['motivo_compra_distinta'] ?? null,
            );
        } catch (OperacionRechazadaException $exception) {
            return $this->respuestaRechazo($exception);
        } catch (VersionDesactualizadaException) {
            return $this->respuestaVersionDesactualizada($pedido);
        }

        return $this->operacionResponse($resultado);
    }

    /**
     * Retira la publicación sin ingresos para una nueva revisión documental.
     */
    public function returnForCorrection(
        ReturnPedidoInventarioRequest $request,
        string $order,
        PedidoRetiroCancelacionService $retiro,
    ): JsonResponse {
        $pedido = $this->findPedido($order);

        Gate::authorize('publishCapability', $pedido);

        $validated = $request->validated();

        try {
            $resultado = $retiro->retirar(
                $pedido,
                $request->user(),
                (int) $validated['version'],
                (string) $validated['motivo'],
                (string) $validated['client_operation_id'],
            );
        } catch (OperacionRechazadaException $exception) {
            return $this->respuestaRechazo($exception);
        } catch (VersionDesactualizadaException) {
            return $this->respuestaVersionDesactualizada($pedido);
        }

        return $this->operacionResponse($resultado);
    }

    /**
     * Cancela un pedido antes de cualquier ingreso (terminal, R15).
     */
    public function cancel(
        CancelPedidoInventarioRequest $request,
        string $order,
        PedidoRetiroCancelacionService $cancelacion,
    ): JsonResponse {
        $pedido = $this->findPedido($order);

        Gate::authorize('cancelCapability', $pedido);

        $validated = $request->validated();

        try {
            $resultado = $cancelacion->cancelar(
                $pedido,
                $request->user(),
                (int) $validated['version'],
                (string) $validated['motivo'],
                (string) $validated['client_operation_id'],
            );
        } catch (OperacionRechazadaException $exception) {
            return $this->respuestaRechazo($exception);
        } catch (VersionDesactualizadaException) {
            return $this->respuestaVersionDesactualizada($pedido);
        }

        return $this->operacionResponse($resultado);
    }

    private function findPedido(string $order): PedidoInventario
    {
        return PedidoInventario::query()->where('uuid', $order)->firstOrFail();
    }

    /**
     * Historial paginado de eventos del pedido (U7): actor, cambios relevantes
     * y orden descendente por evento. Sin secretos ni payloads crudos: el
     * recurso filtra claves reservadas. Un pedido ajeno responde 404.
     */
    public function history(Request $request, string $order): JsonResponse
    {
        $pedido = $this->findPedido($order);

        Gate::authorize('view', $pedido);

        $validated = $request->validate([
            'tipo' => ['nullable', 'string', 'max:80'],
            'linea_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = PedidoEvento::query()
            ->where('pedido_id', $pedido->pedido_id)
            ->with('actor:id,name')
            ->orderByDesc('evento_id');

        if (! empty($validated['tipo'])) {
            $query->where('event_type', $validated['tipo']);
        }

        if (! empty($validated['linea_id'])) {
            $query->where('pedido_linea_id', (int) $validated['linea_id']);
        }

        $paginator = $query->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'success' => true,
            'data' => PedidoEventoResource::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Recupera el resultado de una operación idempotente por comando y UUID
     * (plan sección 17). Alcance estricto actor+pedido: la operación debe
     * pertenecer al usuario autenticado y al pedido consultado; cualquier otra
     * combinación responde 404 sin revelar existencia. La capacidad de lectura
     * se revalida, de modo que un usuario degradado no obtiene el replay.
     */
    public function operation(Request $request, string $order, string $command, string $operationUuid): JsonResponse
    {
        $pedido = $this->findPedido($order);

        Gate::authorize('view', $pedido);

        if (! in_array($command, self::COMMANDS_RECUPERABLES, true) || ! Str::isUuid($operationUuid)) {
            abort(404);
        }

        $operacion = PedidoOperacion::query()
            ->where('actor_id', (int) $request->user()->id)
            ->where('command', $command)
            ->where('client_operation_id', $operationUuid)
            ->where('referencia', (string) $pedido->uuid)
            ->first();

        if ($operacion === null) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'data' => $operacion->resultado ?? [],
            'operacion' => [
                'command' => (string) $operacion->command,
                'client_operation_id' => (string) $operacion->client_operation_id,
                'estado' => (string) $operacion->estado,
                'completada_at' => $operacion->completada_at?->toISOString(),
            ],
            'idempotent_replay' => true,
        ]);
    }

    /**
     * Reemplaza el conjunto de líneas del borrador conservando el linea_id de
     * los órdenes que siguen existiendo y eliminando físicamente los órdenes
     * retirados. Solo se invoca en borrador y bajo lock.
     *
     * Para líneas existentes, los campos ausentes del payload conservan su
     * valor (una corrección confirmada no borra silenciosamente el raw ni los
     * valores confirmados previamente). El vínculo a producto solo se acepta
     * por selección explícita y se valida contra el SKU confirmado bajo la
     * collation real del catálogo; nunca hay match difuso por nombre.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function reemplazarLineas(PedidoInventario $pedido, array $lineas): void
    {
        $existentes = $pedido->lineas()->get()->keyBy('orden');
        $ordenes = [];

        foreach ($lineas as $index => $linea) {
            $orden = (int) $linea['orden'];
            $existente = $existentes->get($orden);

            $datos = [
                'cantidad_esperada' => DecimalQuantity::normalize(
                    $linea['cantidad_esperada'],
                    false,
                    "lineas.{$index}.cantidad_esperada",
                ),
            ];

            $camposOpcionales = [
                'sku_raw',
                'descripcion_raw',
                'sku_confirmado',
                'descripcion_confirmada',
                'unidad',
            ];

            foreach ($camposOpcionales as $campo) {
                if (array_key_exists($campo, $linea)) {
                    $datos[$campo] = $linea[$campo];
                } elseif ($existente === null) {
                    $datos[$campo] = null;
                }
            }

            if (array_key_exists('producto_id', $linea)) {
                $skuEfectivo = array_key_exists('sku_confirmado', $linea)
                    ? $linea['sku_confirmado']
                    : $existente?->sku_confirmado;

                $datos['producto_id'] = $this->resolverProductoExplicito(
                    $linea['producto_id'],
                    $skuEfectivo,
                    $index,
                );
            } elseif ($existente === null) {
                $datos['producto_id'] = null;
            }

            if ($existente instanceof PedidoInventarioLinea) {
                $existente->fill($datos);
                $existente->save();
            } else {
                $pedido->lineas()->create($datos + ['orden' => $orden]);
            }

            $ordenes[] = $orden;
        }

        $pedido->lineas()
            ->whereNotIn('orden', $ordenes)
            ->get()
            ->each(fn (PedidoInventarioLinea $linea) => $linea->delete());

        $pedido->unsetRelation('lineas');
    }

    /**
     * Selección humana explícita de producto. Si además llega un SKU confirmado,
     * se exige coincidencia exacta bajo la collation del motor (en MySQL/MariaDB
     * puede ser case-insensitive; en SQLite es sensible). Sin fuzzy matching.
     */
    private function resolverProductoExplicito(?int $productoId, ?string $skuConfirmado, int $index): ?int
    {
        if ($productoId === null) {
            return null;
        }

        $sku = trim((string) $skuConfirmado);

        if ($sku === '') {
            return $productoId;
        }

        $coincide = Producto::query()
            ->whereKey($productoId)
            ->where('producto_sku', $sku)
            ->exists();

        if (! $coincide) {
            throw ValidationException::withMessages([
                "lineas.{$index}.producto_id" => 'El producto seleccionado no coincide con el SKU confirmado bajo la collation del catálogo.',
            ]);
        }

        return $productoId;
    }

    private function findOperation(int $actorId, string $command, string $operationId, bool $lock = false): ?PedidoOperacion
    {
        $query = PedidoOperacion::query()
            ->where('actor_id', $actorId)
            ->where('command', $command)
            ->where('client_operation_id', $operationId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function replayResponse(PedidoOperacion $operacion, string $hash): JsonResponse
    {
        if (! hash_equals((string) $operacion->canonical_request_hash, $hash)) {
            return response()->json([
                'message' => 'client_operation_id ya fue utilizado con un pedido diferente.',
            ], 409);
        }

        return $this->mutationResponse($operacion->resultado ?? [], true, 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mutationResponse(array $payload, bool $replay, int $status): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $payload,
            'idempotent_replay' => $replay,
        ], $status);
    }

    /**
     * Hash canónico estable: incluye identidad, versión y cantidades exactas;
     * excluye tiempos de transporte. Mismo UUID con hash distinto = 409.
     *
     * @param  array<string, mixed>  $payload
     */
    private function operationHash(array $payload, ?string $pedidoUuid = null): string
    {
        $lineas = null;

        if (array_key_exists('lineas', $payload)) {
            $lineas = array_map(fn (array $linea): array => [
                'orden' => (int) $linea['orden'],
                'sku_raw' => $linea['sku_raw'] ?? null,
                'sku_confirmado' => $linea['sku_confirmado'] ?? null,
                'descripcion_raw' => $linea['descripcion_raw'] ?? null,
                'descripcion_confirmada' => $linea['descripcion_confirmada'] ?? null,
                'cantidad_esperada' => DecimalQuantity::normalize($linea['cantidad_esperada'], false),
                'unidad' => $linea['unidad'] ?? null,
                'producto_id' => isset($linea['producto_id']) ? (int) $linea['producto_id'] : null,
            ], $payload['lineas']);

            usort($lineas, fn (array $a, array $b): int => $a['orden'] <=> $b['orden']);
        }

        $canonical = [
            'pedido_uuid' => $pedidoUuid,
            'proveedor_nombre_raw' => $payload['proveedor_nombre_raw'] ?? null,
            'proveedor_nombre_confirmado' => $payload['proveedor_nombre_confirmado'] ?? null,
            'proveedor_clave' => $payload['proveedor_clave'] ?? null,
            'referencia_factura' => $payload['referencia_factura'] ?? null,
            'bodega_id' => isset($payload['bodega_id']) ? (int) $payload['bodega_id'] : null,
            'version' => isset($payload['version']) ? (int) $payload['version'] : null,
            'lineas' => $lineas,
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     */
    private function registrarEvento(
        PedidoInventario $pedido,
        User $actor,
        string $tipo,
        ?array $antes,
        array $despues,
        string $commandId,
    ): void {
        PedidoEvento::create([
            'pedido_id' => $pedido->pedido_id,
            'event_type' => $tipo,
            'actor_user_id' => $actor->id,
            'actor_rol_snapshot' => (string) $actor->role,
            'datos_antes' => $antes,
            'datos_despues' => $despues,
            'command_id' => $commandId,
            'revision' => $pedido->versionActual(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pedidoSnapshot(PedidoInventario $pedido): array
    {
        return PedidoSnapshot::capturar($pedido);
    }

    private function respuestaRechazo(OperacionRechazadaException $exception): JsonResponse
    {
        return response()->json(
            array_merge(['message' => $exception->getMessage()], $exception->contexto),
            $exception->httpStatus,
        );
    }

    private function respuestaVersionDesactualizada(PedidoInventario $pedido): JsonResponse
    {
        return response()->json([
            'message' => 'La versión enviada no coincide con la versión vigente del pedido.',
            'current_version' => (int) PedidoInventario::query()
                ->where('uuid', $pedido->uuid)
                ->value('version'),
        ], 409);
    }

    /**
     * @param  array{operacion: PedidoOperacion, replay: bool}  $resultado
     */
    private function operacionResponse(array $resultado): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $resultado['operacion']->resultado ?? [],
            'idempotent_replay' => $resultado['replay'],
        ], 200);
    }
}
