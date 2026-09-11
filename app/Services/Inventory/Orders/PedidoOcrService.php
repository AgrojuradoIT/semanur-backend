<?php

namespace App\Services\Inventory\Orders;

use App\Domain\InventoryOrders\AlertaTipo;
use App\Domain\InventoryOrders\InvariantePedidoException;
use App\Domain\InventoryOrders\OcrIntentoActivoException;
use App\Domain\InventoryOrders\OcrIntentoEstado;
use App\Domain\InventoryOrders\PedidoEstado;
use App\DTO\InventoryOrders\DecimalQuantity;
use App\Models\PedidoDocumento;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoOcrIntento;
use App\Models\User;
use App\Services\Inventory\Orders\Ocr\OcrException;
use App\Services\Inventory\Orders\Ocr\OcrLease;
use App\Services\Inventory\Orders\Ocr\OcrProcesamientoResultado;
use App\Services\Inventory\Orders\Ocr\PedidoOcrProvider;
use App\Services\Inventory\Orders\Ocr\PedidoOcrProviderFactory;
use App\Services\Inventory\Orders\Ocr\SiigoScreenshotMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Pipeline OCR del pedido (U4).
 *
 * - solicitar(): crea la solicitud durable (intento + evento outbox) en UNA
 *   transacción, idempotente: si ya hay un intento activo para el documento y
 *   proveedor, devuelve ese intento sin crear otro.
 * - procesar(): adquiere un lease atómico con fencing token, llama al proveedor
 *   (fuera de locks), mapea, valida el contrato y finaliza con guarda de
 *   fencing. Un worker vencido o reemplazado jamás sobrescribe un resultado más
 *   nuevo. Los errores reintentables liberan el lease; los terminales fallan de
 *   inmediato. Cero efectos de stock: este servicio no toca el ledger.
 *
 * Reintentos y estado: el plan no define una transición procesando -> pendiente
 * (máquina de estados U1). Tras un error reintentable el intento queda en
 * `procesando` SIN lease activo (lease_expires_at vencido y lease_token null),
 * por lo que es reclamable de inmediato; el backoff lo impone el job.
 */
class PedidoOcrService
{
    public const ORIGEN_UPLOAD = 'upload';

    public const ORIGEN_REPROCESO = 'reproceso';

    public const EVENTO_OCR_SOLICITADO = 'ocr_solicitado';

    public function __construct(
        private readonly PedidoDocumentoService $documentos,
        private readonly SiigoScreenshotMapper $mapper,
        private readonly PedidoAlertaService $alertas,
        private readonly ?PedidoOcrProvider $proveedor = null,
    ) {}

    /**
     * Crea (o devuelve) la solicitud durable de OCR para un pantallazo Siigo.
     */
    public function solicitar(
        PedidoDocumento $documento,
        string $origen,
        ?User $actor = null,
        bool $rechazarSiActivo = false,
    ): PedidoOcrIntento {
        if ((string) $documento->rol !== PedidoDocumentoService::ROL_PANTALLAZO_SIIGO) {
            throw new InvariantePedidoException('El OCR solo aplica a pantallazos de Siigo (pantallazo_siigo).');
        }

        if (! in_array($origen, [self::ORIGEN_UPLOAD, self::ORIGEN_REPROCESO], true)) {
            throw new InvalidArgumentException(sprintf('Origen de solicitud OCR no soportado: %s.', $origen));
        }

        return DB::transaction(function () use ($documento, $origen, $actor, $rechazarSiActivo): PedidoOcrIntento {
            $bloqueado = PedidoDocumento::query()
                ->whereKey($documento->documento_id)
                ->lockForUpdate()
                ->firstOrFail();

            $pedido = PedidoInventario::query()
                ->whereKey($bloqueado->pedido_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $pedido->estado === PedidoEstado::CANCELADO) {
                throw new InvariantePedidoException('El pedido está cancelado y no admite solicitudes OCR.');
            }

            $proveedor = $this->proveedorNombre();

            $activo = PedidoOcrIntento::query()
                ->where('documento_id', $bloqueado->documento_id)
                ->where('proveedor', $proveedor)
                ->whereIn('estado', [OcrIntentoEstado::PENDIENTE, OcrIntentoEstado::PROCESANDO])
                ->lockForUpdate()
                ->first();

            if ($activo !== null) {
                if ($rechazarSiActivo) {
                    throw new OcrIntentoActivoException((int) $activo->intento_id);
                }

                return $activo;
            }

            if ($origen === self::ORIGEN_REPROCESO) {
                $this->obsolescerResultadosVigentes((int) $bloqueado->documento_id);
            }

            $generacion = ((int) PedidoOcrIntento::query()
                ->where('documento_id', $bloqueado->documento_id)
                ->count()) + 1;

            $intento = PedidoOcrIntento::create([
                'documento_id' => $bloqueado->documento_id,
                'documento_revision' => $bloqueado->documento_revision,
                'proveedor' => $proveedor,
                'proveedor_version' => null,
                'mapper_version' => null,
                'clave_trabajo' => $this->claveTrabajo($bloqueado, $proveedor, $generacion),
                'estado' => OcrIntentoEstado::PENDIENTE,
                'intentos' => 0,
                'max_intentos' => $this->maxIntentos(),
            ]);

            PedidoEvento::create([
                'pedido_id' => $bloqueado->pedido_id,
                'pedido_linea_id' => $bloqueado->pedido_linea_id,
                'event_type' => self::EVENTO_OCR_SOLICITADO,
                'actor_user_id' => $actor?->id,
                'actor_rol_snapshot' => $actor !== null ? (string) $actor->role : null,
                'datos_antes' => null,
                'datos_despues' => [
                    'intento_id' => $intento->intento_id,
                    'documento_id' => $bloqueado->documento_id,
                    'documento_revision' => $bloqueado->documento_revision,
                    'proveedor' => $proveedor,
                    'config_version' => $this->configVersion(),
                    'origen' => $origen,
                ],
                'revision' => $pedido->versionActual(),
            ]);

            return $intento;
        });
    }

    /**
     * Ejecuta un intento: lease + proveedor + mapper + validación + finalización
     * con fencing. Idempotente ante jobs repetidos.
     */
    public function procesar(PedidoOcrIntento $intento): OcrProcesamientoResultado
    {
        $lease = $this->reclamar((int) $intento->intento_id);

        if ($lease === null) {
            return OcrProcesamientoResultado::noOp('El intento OCR no es reclamable (terminal, vigente o con lease activo).');
        }

        if ($this->pedidoCancelado($lease->intento)) {
            $this->cancelarIntento($lease);

            return OcrProcesamientoResultado::cancelado();
        }

        try {
            $ruta = $this->rutaDerivado($lease->intento);
            $documento = PedidoDocumento::query()->find($lease->intento->documento_id);

            $contexto = [
                'documento_id' => (int) $lease->intento->documento_id,
                'documento_revision' => (int) $lease->intento->documento_revision,
                'pedido_id' => $documento?->pedido_id,
                'intento_id' => (int) $lease->intento->intento_id,
                'provider' => (string) $lease->intento->proveedor,
                'provider_version' => $lease->intento->proveedor_version,
            ];

            $crudo = $this->proveedor()->extraer($ruta, 'image/jpeg', $contexto);
            $contrato = $this->mapper->mapear($crudo, $contexto);
            $this->validarContrato($contrato);
        } catch (OcrException $exception) {
            return $this->registrarFallo($lease, $exception->errorCode(), $exception->getMessage(), $exception->esReintentable());
        } catch (Throwable $exception) {
            Log::error('pedido_ocr.procesamiento_error', [
                'intento_id' => $lease->intento->intento_id,
                'documento_id' => $lease->intento->documento_id,
                'error' => $exception->getMessage(),
            ]);

            return $this->registrarFallo($lease, 'error_interno', 'Error interno al procesar el OCR.', false);
        }

        $estado = ($contrato['requires_human_revision'] ?? false) === true
            ? OcrIntentoEstado::REQUIERE_REVISION
            : OcrIntentoEstado::COMPLETADO;

        $finalizacion = $this->finalizar($lease, $estado, $contrato);

        if ($finalizacion === null) {
            Log::warning('pedido_ocr.resultado_descartado', [
                'intento_id' => $lease->intento->intento_id,
                'motivo' => 'lease reemplazado por un worker más nuevo o intento ya no procesable',
            ]);

            return OcrProcesamientoResultado::noOp('Resultado descartado: el lease fue reemplazado por un worker más nuevo.');
        }

        if ($finalizacion === 'cancelado') {
            return OcrProcesamientoResultado::cancelado();
        }

        return $estado === OcrIntentoEstado::REQUIERE_REVISION
            ? OcrProcesamientoResultado::requiereRevision()
            : OcrProcesamientoResultado::completado();
    }

    /**
     * Fallback del job cuando la cola agota sus intentos.
     *
     * - `procesando` sin lease vivo => `fallido` (transición permitida).
     * - `pendiente` (el job murió antes de reclamar, p. ej. error de
     *   infraestructura) => `cancelado`, también terminal según la máquina U1;
     *   `pendiente -> fallido` no está autorizado por la máquina de estados.
     */
    public function marcarFallidoPorAgotamiento(PedidoOcrIntento $intento, ?string $mensaje = null): void
    {
        DB::transaction(function () use ($intento, $mensaje): void {
            $actual = PedidoOcrIntento::query()
                ->whereKey($intento->intento_id)
                ->lockForUpdate()
                ->first();

            if ($actual === null) {
                return;
            }

            $estado = (string) $actual->estado;

            if (! in_array($estado, [OcrIntentoEstado::PENDIENTE, OcrIntentoEstado::PROCESANDO], true)) {
                return;
            }

            if ($actual->lease_token !== null && $actual->lease_expires_at !== null && $actual->lease_expires_at->isFuture()) {
                return;
            }

            $actual->error_code = 'intentos_agotados';
            $actual->error_mensaje = mb_substr($mensaje ?? 'La cola agotó los intentos del job OCR.', 0, 500);
            $actual->estado = $estado === OcrIntentoEstado::PROCESANDO
                ? OcrIntentoEstado::FALLIDO
                : OcrIntentoEstado::CANCELADO;
            $actual->finished_at = now();
            $actual->lease_token = null;
            $actual->lease_expires_at = null;
            $actual->save();

            if ((string) $actual->estado === OcrIntentoEstado::FALLIDO) {
                $this->alertarDesdeIntento($actual, AlertaTipo::OCR_FALLIDO);
            }
        });
    }

    /**
     * Adquiere el lease de forma atómica. Devuelve null si el intento es
     * terminal/vigente o si otro worker tiene un lease vivo.
     */
    private function reclamar(int $intentoId): ?OcrLease
    {
        return DB::transaction(function () use ($intentoId): ?OcrLease {
            $intento = PedidoOcrIntento::query()
                ->whereKey($intentoId)
                ->lockForUpdate()
                ->firstOrFail();

            $estado = (string) $intento->estado;

            if (! in_array($estado, [OcrIntentoEstado::PENDIENTE, OcrIntentoEstado::PROCESANDO], true)) {
                return null;
            }

            if ($estado === OcrIntentoEstado::PROCESANDO && ! $this->leaseVencido($intento)) {
                return null;
            }

            $token = (string) Str::uuid();

            $intento->lease_token = $token;
            $intento->fencing_token = ((int) $intento->fencing_token) + 1;
            $intento->lease_expires_at = now()->addSeconds($this->leaseSegundos());
            $intento->intentos = ((int) $intento->intentos) + 1;

            if ($intento->started_at === null) {
                $intento->started_at = now();
            }

            if ($estado === OcrIntentoEstado::PENDIENTE) {
                $intento->estado = OcrIntentoEstado::PROCESANDO;
            }

            $intento->save();

            return new OcrLease($intento, $token);
        });
    }

    private function registrarFallo(OcrLease $lease, string $errorCode, string $mensaje, bool $reintentable): OcrProcesamientoResultado
    {
        $desenlace = DB::transaction(function () use ($lease, $errorCode, $mensaje, $reintentable): string {
            $intento = PedidoOcrIntento::query()
                ->whereKey($lease->intento->intento_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($intento->lease_token !== $lease->token || (string) $intento->estado !== OcrIntentoEstado::PROCESANDO) {
                return 'obsoleto';
            }

            $intento->error_code = $errorCode;
            $intento->error_mensaje = mb_substr($mensaje, 0, 500);

            $agotado = ((int) $intento->intentos) >= ((int) $intento->max_intentos);

            if ($reintentable && ! $agotado) {
                // Sin transición procesando -> pendiente en la máquina U1: se
                // suelta el lease y el intento queda reclamable de inmediato.
                $intento->lease_token = null;
                $intento->lease_expires_at = now()->subSecond();
                $intento->save();

                return 'reintentar';
            }

            $intento->estado = OcrIntentoEstado::FALLIDO;
            $intento->finished_at = now();
            $intento->lease_token = null;
            $intento->lease_expires_at = null;
            $intento->save();

            $this->alertarDesdeIntento($intento, AlertaTipo::OCR_FALLIDO);

            return 'fallido';
        });

        return match ($desenlace) {
            'reintentar' => OcrProcesamientoResultado::reintentar($errorCode, $mensaje),
            'obsoleto' => OcrProcesamientoResultado::noOp('Fallo descartado: el lease fue reemplazado.'),
            default => OcrProcesamientoResultado::fallido($errorCode, $mensaje),
        };
    }

    /**
     * @param  array<string, mixed>  $contrato
     * @return 'ok'|'cancelado'|null null = resultado fenced/obsoleto descartado
     */
    private function finalizar(OcrLease $lease, string $estado, array $contrato): ?string
    {
        return DB::transaction(function () use ($lease, $estado, $contrato): ?string {
            $intento = PedidoOcrIntento::query()
                ->whereKey($lease->intento->intento_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($intento->lease_token !== $lease->token || (string) $intento->estado !== OcrIntentoEstado::PROCESANDO) {
                return null;
            }

            if ($this->pedidoCancelado($intento)) {
                $intento->estado = OcrIntentoEstado::CANCELADO;
                $intento->finished_at = now();
                $intento->lease_token = null;
                $intento->lease_expires_at = null;
                $intento->save();

                return 'cancelado';
            }

            $intento->resultado = $contrato;
            $intento->mapper_version = (string) ($contrato['mapper_version'] ?? SiigoScreenshotMapper::MAPPER_VERSION);
            $intento->proveedor_version = $contrato['provider_version'] ?? $intento->proveedor_version;
            $intento->error_code = null;
            $intento->error_mensaje = null;
            $intento->estado = $estado;
            $intento->finished_at = now();
            $intento->lease_token = null;
            $intento->lease_expires_at = null;
            $intento->save();

            if ($estado === OcrIntentoEstado::REQUIERE_REVISION) {
                $this->alertarDesdeIntento($intento, AlertaTipo::REQUIERE_REVISION);
            }

            return 'ok';
        });
    }

    private function cancelarIntento(OcrLease $lease): void
    {
        DB::transaction(function () use ($lease): void {
            $intento = PedidoOcrIntento::query()
                ->whereKey($lease->intento->intento_id)
                ->lockForUpdate()
                ->first();

            if ($intento === null
                || $intento->lease_token !== $lease->token
                || (string) $intento->estado !== OcrIntentoEstado::PROCESANDO) {
                return;
            }

            $intento->estado = OcrIntentoEstado::CANCELADO;
            $intento->finished_at = now();
            $intento->lease_token = null;
            $intento->lease_expires_at = null;
            $intento->save();
        });
    }

    private function obsolescerResultadosVigentes(int $documentoId): void
    {
        $vigentes = PedidoOcrIntento::query()
            ->where('documento_id', $documentoId)
            ->whereIn('estado', [OcrIntentoEstado::COMPLETADO, OcrIntentoEstado::REQUIERE_REVISION])
            ->lockForUpdate()
            ->get();

        foreach ($vigentes as $previo) {
            if (! OcrIntentoEstado::permiteTransicion((string) $previo->estado, OcrIntentoEstado::OBSOLETO)) {
                continue;
            }

            $previo->estado = OcrIntentoEstado::OBSOLETO;
            $previo->save();
        }
    }

    /**
     * Alerta Web persistente para el autor del borrador cuando el OCR queda en
     * revisión o falla. El evento de auditoría se crea siempre; la alerta solo
     * si el autor sigue activo y con capacidad documental. Sin FCM ni
     * Notificacion.
     */
    private function alertarDesdeIntento(PedidoOcrIntento $intento, string $tipo): void
    {
        $documento = PedidoDocumento::query()->find($intento->documento_id);

        if ($documento === null) {
            return;
        }

        $pedido = PedidoInventario::query()->find($documento->pedido_id);

        if ($pedido === null) {
            return;
        }

        $evento = PedidoEvento::create([
            'pedido_id' => $pedido->pedido_id,
            'pedido_linea_id' => $documento->pedido_linea_id,
            'event_type' => $tipo,
            'actor_user_id' => null,
            'actor_rol_snapshot' => null,
            'datos_antes' => null,
            'datos_despues' => [
                'intento_id' => $intento->intento_id,
                'documento_id' => $documento->documento_id,
                'estado' => (string) $intento->estado,
                'error_code' => $intento->error_code,
            ],
            'revision' => $pedido->versionActual(),
        ]);

        $autor = $this->alertas->autorCorrectorHabilitado($pedido->created_by);

        if ($autor !== null) {
            $this->alertas->crearParaEvento($evento, $tipo, [$autor]);
        }
    }

    private function rutaDerivado(PedidoOcrIntento $intento): string
    {
        $documento = PedidoDocumento::query()->find($intento->documento_id);

        if ($documento === null) {
            throw OcrException::terminal('documento_ausente', 'El documento del intento OCR ya no existe.');
        }

        $disk = $this->documentos->diskName();
        $ruta = PedidoDocumentoService::derivativePathFor((string) $documento->storage_key);
        $storage = Storage::disk($disk);

        if (! $storage->exists($ruta)) {
            throw OcrException::terminal('documento_derivado_ausente', 'No existe el derivado privado del documento para OCR.');
        }

        return $storage->path($ruta);
    }

    /**
     * @param  array<string, mixed>  $contrato
     */
    private function validarContrato(array $contrato): void
    {
        if (($contrato['schema_version'] ?? null) !== SiigoScreenshotMapper::SCHEMA_VERSION) {
            throw OcrException::terminal('contrato_invalido', 'El contrato OCR no declara schema_version 1.');
        }

        if (! is_array($contrato['lines'] ?? null)
            || ! is_array($contrato['ignored_rows'] ?? null)
            || ! is_array($contrato['warnings'] ?? null)
            || ! is_bool($contrato['requires_human_revision'] ?? null)) {
            throw OcrException::terminal('contrato_invalido', 'La estructura del contrato OCR está incompleta.');
        }

        foreach ($contrato['lines'] as $linea) {
            if (! is_array($linea)) {
                throw OcrException::terminal('contrato_invalido', 'Una línea del contrato OCR no es un objeto.');
            }

            $candidateId = $linea['candidate_id'] ?? null;

            if (! is_string($candidateId) || ! Str::isUuid($candidateId)) {
                throw OcrException::terminal('contrato_invalido', 'Una línea del contrato OCR no tiene candidate_id UUID.');
            }

            $cantidad = $linea['expected_quantity']['value'] ?? null;

            if ($cantidad !== null && (! is_string($cantidad) || ! DecimalQuantity::isCanonical($cantidad))) {
                throw OcrException::terminal('contrato_invalido', 'El contrato OCR contiene una cantidad no canónica.');
            }
        }
    }

    private function pedidoCancelado(PedidoOcrIntento $intento): bool
    {
        $pedidoId = PedidoDocumento::query()
            ->whereKey($intento->documento_id)
            ->value('pedido_id');

        if ($pedidoId === null) {
            return false;
        }

        return (string) PedidoInventario::query()->whereKey($pedidoId)->value('estado') === PedidoEstado::CANCELADO;
    }

    private function leaseVencido(PedidoOcrIntento $intento): bool
    {
        return $intento->lease_token === null
            || $intento->lease_expires_at === null
            || $intento->lease_expires_at->isPast();
    }

    private function proveedor(): PedidoOcrProvider
    {
        return $this->proveedor ?? PedidoOcrProviderFactory::resolver();
    }

    private function proveedorNombre(): string
    {
        return strtolower(trim((string) config('inventory_orders.ocr.provider', 'fake')));
    }

    private function configVersion(): string
    {
        return (string) config('inventory_orders.ocr.config_version', 'v1');
    }

    private function maxIntentos(): int
    {
        return max(1, (int) config('inventory_orders.ocr.max_intentos', 3));
    }

    private function leaseSegundos(): int
    {
        $timeout = max(1, (int) config('inventory_orders.ocr.timeout', 60));
        $lease = max(30, (int) config('inventory_orders.ocr.lease_seconds', 120));

        return max($timeout + 30, $lease);
    }

    private function claveTrabajo(PedidoDocumento $documento, string $proveedor, int $generacion): string
    {
        return sprintf(
            'ocr:doc%d:r%d:%s:%s:g%d',
            $documento->documento_id,
            $documento->documento_revision,
            $this->slug($proveedor),
            $this->slug($this->configVersion()),
            $generacion,
        );
    }

    private function slug(string $valor): string
    {
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '_', $valor) ?? '';

        return mb_substr(trim($slug, '_-') !== '' ? $slug : 'na', 0, 40);
    }
}
