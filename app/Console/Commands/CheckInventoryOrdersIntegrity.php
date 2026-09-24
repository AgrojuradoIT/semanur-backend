<?php

namespace App\Console\Commands;

use App\Domain\InventoryOrders\LineaEstado;
use App\Domain\InventoryOrders\NovedadEstado;
use App\DTO\InventoryOrders\DecimalQuantity;
use App\Models\PedidoAutorizacion;
use App\Models\PedidoInventarioLinea;
use App\Models\PedidoNovedad;
use App\Models\TransaccionInventario;
use Illuminate\Console\Command;

/**
 * Monitor de integridad de pedidos (U9, plan sección 16 — observabilidad).
 *
 * SOLO LECTURA: verifica invariantes ledger<->pedido y reporta hallazgos.
 * NUNCA repara saldos, reescribe estados ni crea movimientos. Código de
 * salida 0 = limpio, 1 = hay hallazgos.
 *
 * Chequeos:
 *  (a) línea ingresada (recibida / recibida_con_desfase / recibida_parcial)
 *      → EXACTAMENTE una fila de ledger con su pedido_linea_id y cantidad,
 *      bodega, producto y referencia coherentes;
 *  (b) línea bloqueada (estado no terminal o novedad abierta) → CERO filas;
 *  (c) cierre con desfase / cierre sin ingreso → autorización consumida;
 *  (d) conteo de novedades abiertas + antigüedad (stale configurable).
 */
class CheckInventoryOrdersIntegrity extends Command
{
    protected $signature = 'inventory-orders:check-integrity
                            {--format=human : Formato de salida: human|json}
                            {--stale-days=7 : Días tras los cuales una novedad abierta se reporta como estancada}';

    protected $description = 'Verifica (solo lectura) la coherencia entre líneas de pedido ingresadas/bloqueadas y el ledger de inventario';

    /** @var list<array<string, mixed>> */
    private array $hallazgos = [];

    public function handle(): int
    {
        // La instancia del comando se reutiliza entre corridas del scheduler
        // en el mismo proceso: el estado siempre se reinicia aquí.
        $this->hallazgos = [];

        $formato = strtolower((string) $this->option('format'));

        if (! in_array($formato, ['human', 'json'], true)) {
            $this->error('--format debe ser human o json.');

            return self::INVALID;
        }

        $staleDays = max(1, (int) $this->option('stale-days'));

        $lineas = PedidoInventarioLinea::query()
            ->with(['pedido:pedido_id,uuid,numero,bodega_id'])
            ->orderBy('linea_id')
            ->get([
                'linea_id', 'pedido_id', 'parent_linea_id', 'orden', 'estado',
                'cantidad_esperada', 'producto_id', 'sku_confirmado',
            ]);

        $ledgerPorLinea = TransaccionInventario::query()
            ->whereNotNull('pedido_linea_id')
            ->get([
                'transaccion_id', 'pedido_linea_id', 'producto_id', 'bodega_id',
                'transaccion_tipo', 'transaccion_cantidad',
                'transaccion_referencia_type', 'transaccion_referencia_id',
            ])
            ->groupBy('pedido_linea_id');

        $novedadesAbiertas = PedidoNovedad::query()
            ->where('estado', NovedadEstado::ABIERTA)
            ->orderBy('abierta_at')
            ->get(['novedad_id', 'pedido_linea_id', 'tipo', 'abierta_at']);

        $novedadesAbiertasPorLinea = $novedadesAbiertas->groupBy('pedido_linea_id');

        $autorizacionesPorNovedad = PedidoAutorizacion::query()
            ->whereIn('novedad_id', PedidoNovedad::query()
                ->whereIn('pedido_linea_id', $lineas->pluck('linea_id')->all())
                ->pluck('novedad_id')
                ->all())
            ->get()
            ->groupBy('novedad_id');

        $novedadesPorLinea = PedidoNovedad::query()
            ->whereIn('pedido_linea_id', $lineas->pluck('linea_id')->all())
            ->get(['novedad_id', 'pedido_linea_id', 'estado'])
            ->groupBy('pedido_linea_id');

        foreach ($lineas as $linea) {
            $this->revisarLinea(
                $linea,
                $ledgerPorLinea->get($linea->linea_id, collect()),
                $novedadesAbiertasPorLinea->get($linea->linea_id, collect()),
                $novedadesPorLinea->get($linea->linea_id, collect()),
                $autorizacionesPorNovedad,
            );
        }

        $this->revisarAutorizacionesConsumidas();
        $resumen = $this->resumen($lineas->count(), $novedadesAbiertas, $staleDays);

        if ($formato === 'json') {
            $this->line(json_encode([
                'clean' => $this->hallazgos === [],
                'findings' => $this->hallazgos,
                'summary' => $resumen,
            ], JSON_UNESCAPED_UNICODE));
        } else {
            $this->informeHumano($resumen);
        }

        return $this->hallazgos === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TransaccionInventario>  $movimientos
     * @param  \Illuminate\Support\Collection<int, PedidoNovedad>  $abiertas
     * @param  \Illuminate\Support\Collection<int, PedidoNovedad>  $todas
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, PedidoAutorizacion>>  $auths
     */
    private function revisarLinea(
        PedidoInventarioLinea $linea,
        $movimientos,
        $abiertas,
        $todas,
        $auths,
    ): void {
        $contexto = [
            'linea_id' => (int) $linea->linea_id,
            'pedido_uuid' => $linea->pedido?->uuid,
            'pedido_numero' => $linea->pedido?->numero,
            'estado_linea' => (string) $linea->estado,
        ];
        $estado = (string) $linea->estado;

        if (in_array($estado, [
            LineaEstado::RECIBIDA,
            LineaEstado::RECIBIDA_CON_DESFASE,
            LineaEstado::RECIBIDA_PARCIAL,
        ], true)) {
            if ($movimientos->count() !== 1) {
                $this->hallar('LEDGER_COUNT_MISMATCH', $contexto + [
                    'detail' => sprintf(
                        'La línea ingresada debe tener exactamente 1 movimiento; tiene %d.',
                        $movimientos->count(),
                    ),
                ]);

                return;
            }

            /** @var TransaccionInventario $movimiento */
            $movimiento = $movimientos->sole();
            $this->revisarCoherencia($linea, $movimiento, $contexto);

            if ($estado === LineaEstado::RECIBIDA) {
                $this->compararCantidades($linea, (string) $movimiento->transaccion_cantidad, (string) $linea->cantidad_esperada, $contexto);
            }

            if ($estado === LineaEstado::RECIBIDA_PARCIAL) {
                $this->revisarSaldoParcial($linea, $movimiento, $contexto);
            }

            if ($estado === LineaEstado::RECIBIDA_CON_DESFASE) {
                $this->revisarDesfaseConsumido($linea, $movimiento, $todas, $auths, $contexto);
            }

            return;
        }

        if ($estado === LineaEstado::CIERRE_SIN_INGRESO) {
            if ($movimientos->count() !== 0) {
                $this->hallar('CIERRE_SIN_INGRESO_WITH_LEDGER', $contexto + [
                    'detail' => 'El cierre sin ingreso no debe tener movimientos.',
                ]);
            }

            $this->revisarDesfaseConsumido($linea, null, $todas, $auths, $contexto);

            return;
        }

        // Línea bloqueada / en curso: pendiente, conforme, novedad.
        if ($movimientos->count() !== 0) {
            $this->hallar('BLOCKED_LINE_WITH_LEDGER', $contexto + [
                'detail' => sprintf(
                    'La línea no terminal tiene %d movimiento(s); las líneas bloqueadas quedan fuera del stock.',
                    $movimientos->count(),
                ),
            ]);
        }

        if ($abiertas->isNotEmpty() && $movimientos->count() !== 0) {
            $this->hallar('OPEN_NOVEDAD_WITH_LEDGER', $contexto + [
                'detail' => 'La línea con novedad abierta no debe tener movimientos.',
            ]);
        }
    }

    private function revisarCoherencia(
        PedidoInventarioLinea $linea,
        TransaccionInventario $movimiento,
        array $contexto,
    ): void {
        if ((string) $movimiento->transaccion_tipo !== 'ingreso') {
            $this->hallar('LEDGER_INCOHERENT', $contexto + [
                'detail' => sprintf('Tipo de movimiento inesperado: %s.', (string) $movimiento->transaccion_tipo),
                'transaccion_id' => (int) $movimiento->transaccion_id,
            ]);
        }

        if ((int) $movimiento->producto_id !== (int) $linea->producto_id) {
            $this->hallar('LEDGER_INCOHERENT', $contexto + [
                'detail' => 'El producto del movimiento no coincide con el de la línea.',
                'transaccion_id' => (int) $movimiento->transaccion_id,
            ]);
        }

        if ($linea->pedido !== null && (int) $movimiento->bodega_id !== (int) $linea->pedido->bodega_id) {
            $this->hallar('LEDGER_INCOHERENT', $contexto + [
                'detail' => 'La bodega del movimiento no coincide con la bodega destino del pedido.',
                'transaccion_id' => (int) $movimiento->transaccion_id,
            ]);
        }

        if ((string) $movimiento->transaccion_referencia_type !== 'pedido_inventario'
            || ($linea->pedido !== null && (int) $movimiento->transaccion_referencia_id !== (int) $linea->pedido->pedido_id)) {
            $this->hallar('LEDGER_INCOHERENT', $contexto + [
                'detail' => 'La referencia del movimiento no apunta al pedido de la línea.',
                'transaccion_id' => (int) $movimiento->transaccion_id,
            ]);
        }

        if ($this->compararCantidadesCanonicas((string) $movimiento->transaccion_cantidad, '0') !== 1) {
            $this->hallar('LEDGER_INCOHERENT', $contexto + [
                'detail' => 'La cantidad ingresada debe ser mayor que cero.',
                'transaccion_id' => (int) $movimiento->transaccion_id,
            ]);
        }
    }

    /**
     * Compara cantidades del ledger (SQLite puede devolver float/int y MySQL
     * string) normalizando primero a la forma canónica; null si alguna no es
     * una cantidad válida del ledger.
     */
    private function compararCantidadesCanonicas(string $a, string $b): ?int
    {
        $normalizadoA = DecimalQuantity::tryNormalize($a, true);
        $normalizadoB = DecimalQuantity::tryNormalize($b, true);

        if ($normalizadoA === null || $normalizadoB === null) {
            return null;
        }

        return DecimalQuantity::compare($normalizadoA, $normalizadoB);
    }

    private function compararCantidades(
        PedidoInventarioLinea $linea,
        string $cantidad,
        string $esperada,
        array $contexto,
    ): void {
        $comparacion = $this->compararCantidadesCanonicas($cantidad, $esperada);

        if ($comparacion === null || $comparacion !== 0) {
            $this->hallar('LEDGER_INCOHERENT', $contexto + [
                'detail' => sprintf(
                    'Línea conforme: ingresado %s ≠ esperado %s.',
                    $cantidad,
                    $esperada,
                ),
            ]);
        }
    }

    private function revisarSaldoParcial(
        PedidoInventarioLinea $linea,
        TransaccionInventario $movimiento,
        array $contexto,
    ): void {
        $cantidad = (string) $movimiento->transaccion_cantidad;
        $esperada = (string) $linea->cantidad_esperada;

        $comparacion = $this->compararCantidadesCanonicas($cantidad, $esperada);

        if ($comparacion === null || $comparacion >= 0) {
            $this->hallar('LEDGER_INCOHERENT', $contexto + [
                'detail' => sprintf(
                    'Saldo parcial: ingresado %s debe ser menor que esperado %s.',
                    $cantidad,
                    $esperada,
                ),
            ]);
        }

        $hija = PedidoInventarioLinea::query()
            ->where('parent_linea_id', $linea->linea_id)
            ->exists();

        if (! $hija) {
            $this->hallar('SALDO_SIN_HIJA', $contexto + [
                'detail' => 'La línea parcial no tiene línea hija con el saldo abierto.',
            ]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PedidoNovedad>  $todas
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, PedidoAutorizacion>>  $auths
     */
    private function revisarDesfaseConsumido(
        PedidoInventarioLinea $linea,
        ?TransaccionInventario $movimiento,
        $todas,
        $auths,
        array $contexto,
    ): void {
        $consumida = null;

        foreach ($todas as $novedad) {
            foreach ($auths->get($novedad->novedad_id, collect()) as $auth) {
                if ($auth->estado === PedidoAutorizacion::ESTADO_CONSUMIDA) {
                    $consumida ??= $auth;
                }
            }
        }

        if ($consumida === null) {
            $this->hallar('DESFASE_WITHOUT_CONSUMED_AUTH', $contexto + [
                'detail' => 'El cierre con desfase no tiene autorización consumida vinculada.',
            ]);

            return;
        }

        $comparacion = $movimiento !== null
            ? $this->compararCantidadesCanonicas(
                (string) $movimiento->transaccion_cantidad,
                (string) $consumida->cantidad_autorizada,
            )
            : null;

        if ($movimiento !== null && $comparacion !== 0) {
            $this->hallar('LEDGER_INCOHERENT', $contexto + [
                'detail' => sprintf(
                    'Ingresado %s ≠ cantidad autorizada %s.',
                    (string) $movimiento->transaccion_cantidad,
                    (string) $consumida->cantidad_autorizada,
                ),
                'autorizacion_id' => (int) $consumida->autorizacion_id,
            ]);
        }
    }

    private function revisarAutorizacionesConsumidas(): void
    {
        $huerfanas = PedidoAutorizacion::query()
            ->where('estado', PedidoAutorizacion::ESTADO_CONSUMIDA)
            ->where(function ($query): void {
                $query->whereNull('consumida_por_recepcion_id')
                    ->orWhereNull('consumida_at');
            })
            ->get(['autorizacion_id', 'novedad_id']);

        foreach ($huerfanas as $auth) {
            $this->hallar('CONSUMED_AUTH_WITHOUT_RECEPCION', [
                'autorizacion_id' => (int) $auth->autorizacion_id,
                'novedad_id' => (int) $auth->novedad_id,
                'detail' => 'Autorización consumida sin recepción consumidora registrada.',
            ]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PedidoNovedad>  $abiertas
     * @return array<string, mixed>
     */
    private function resumen(int $lineas, $abiertas, int $staleDays): array
    {
        $ahora = now();
        $masAntigua = $abiertas->min('abierta_at');
        $stale = 0;

        foreach ($abiertas as $novedad) {
            $abiertaAt = $novedad->abierta_at;

            // diffInDays firmado (Carbon 3): una novedad del pasado da
            // negativo; se usa el valor absoluto para la antigüedad.
            if ($abiertaAt !== null && abs($ahora->diffInDays($abiertaAt)) >= $staleDays) {
                $stale++;

                if ($stale <= 50) {
                    $this->hallar('NOVEDAD_ABIERTA_STALE', [
                        'novedad_id' => (int) $novedad->novedad_id,
                        'linea_id' => (int) $novedad->pedido_linea_id,
                        'tipo' => (string) $novedad->tipo,
                        'detail' => sprintf(
                            'Novedad abierta desde %s (≥ %d días).',
                            $abiertaAt->toDateTimeString(),
                            $staleDays,
                        ),
                    ]);
                }
            }
        }

        return [
            'lineas_revisadas' => $lineas,
            'novedades_abiertas' => $abiertas->count(),
            'novedades_estancadas' => $stale,
            'novedad_mas_antigua_at' => $masAntigua?->toDateTimeString(),
            'hallazgos' => count($this->hallazgos),
        ];
    }

    /** @param array<string, mixed> $hallazgo */
    private function hallar(string $codigo, array $hallazgo): void
    {
        $this->hallazgos[] = ['code' => $codigo] + $hallazgo;
    }

    /** @param array<string, mixed> $resumen */
    private function informeHumano(array $resumen): void
    {
        if ($this->hallazgos === []) {
            $this->info(sprintf(
                'Integridad OK: %d línea(s) revisada(s), %d novedad(es) abierta(s).',
                $resumen['lineas_revisadas'],
                $resumen['novedades_abiertas'],
            ));

            return;
        }

        $this->warn(sprintf('%d hallazgo(s) de integridad:', count($this->hallazgos)));

        foreach ($this->hallazgos as $hallazgo) {
            $this->line(sprintf(
                ' - [%s] línea %s pedido %s: %s',
                $hallazgo['code'],
                $hallazgo['linea_id'] ?? $hallazgo['novedad_id'] ?? '—',
                $hallazgo['pedido_numero'] ?? '—',
                $hallazgo['detail'] ?? '',
            ));
        }

        $this->line(sprintf(
            'Resumen: %d línea(s), %d novedad(es) abierta(s), %d estancada(s).',
            $resumen['lineas_revisadas'],
            $resumen['novedades_abiertas'],
            $resumen['novedades_estancadas'],
        ));
    }
}
