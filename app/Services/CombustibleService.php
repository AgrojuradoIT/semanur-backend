<?php

namespace App\Services;

use App\Models\Bodega;
use App\Models\BodegaProducto;
use App\Models\Producto;
use App\Models\RegistroCombustible;
use App\Models\TransaccionInventario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CombustibleService
{
    private const CORRECTION_REVERSAL_REASON = 'Reversión por corrección de tanqueo';

    private const CORRECTED_OUTPUT_REASON = 'Consumo de Combustible (Corregido)';

    private const DELETION_REVERSAL_REASON = 'Reversión por eliminación de tanqueo';

    /**
     * Resuelve el producto de inventario correspondiente al tipo de combustible.
     * Busca primero en la categoría "combustible", luego en todo el catálogo.
     */
    public function resolveCombustibleProducto(string $tipo): ?Producto
    {
        $normalizedType = strtolower(trim($tipo));

        if (! in_array($normalizedType, ['gasolina', 'acpm'], true)) {
            return null;
        }

        $needle = $normalizedType === 'gasolina' ? 'GASOLINA' : 'ACPM';

        $byCategoria = Producto::whereHas('categoria', function ($q) {
            $q->where('categoria_tipo', 'combustible')
                ->orWhereRaw('LOWER(categoria_nombre) LIKE ?', ['%combustible%']);
        })
            ->where(function ($q) use ($needle) {
                $q->whereRaw('UPPER(producto_nombre) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('UPPER(producto_sku) LIKE ?', ["%{$needle}%"]);
            })
            ->orderByRaw('CASE WHEN UPPER(producto_nombre) = ? THEN 0 ELSE 1 END', [$needle])
            ->first();

        if ($byCategoria) {
            return $byCategoria;
        }

        $fallback = Producto::where(function ($q) use ($needle) {
            $q->whereRaw('UPPER(producto_nombre) LIKE ?', ["%{$needle}%"])
                ->orWhereRaw('UPPER(producto_sku) LIKE ?', ["%{$needle}%"]);
        })
            ->orderByRaw('CASE WHEN UPPER(producto_nombre) = ? THEN 0 ELSE 1 END', [$needle])
            ->first();

        if ($fallback) {
            Log::warning("CombustibleService: producto '{$tipo}' encontrado fuera de categoría combustible", [
                'producto_id' => $fallback->producto_id,
                'producto_nombre' => $fallback->producto_nombre,
            ]);
        }

        return $fallback;
    }

    /**
     * Determina el tipo de referencia y las notas para la transacción de inventario.
     */
    public function buildReferencia(string $tipoDestino, ?int $vehiculoId, ?string $terceroNombre, ?string $labor): array
    {
        $refType = null;
        $refId = null;
        $notas = 'Tanqueo interno';

        if (in_array($tipoDestino, ['vehiculo', 'maquinaria', 'equipo_menor'], true)) {
            $refType = 'Vehiculo';
            $refId = $vehiculoId;
            $etiqueta = match ($tipoDestino) {
                'equipo_menor' => 'equipo menor',
                'maquinaria' => 'maquinaria',
                default => 'vehículo',
            };
            $notas .= " para {$etiqueta} ID {$vehiculoId}";
            if ($tipoDestino === 'equipo_menor' && $terceroNombre) {
                $notas .= " entregado a tercero: {$terceroNombre}";
            }
        } elseif ($tipoDestino === 'empleado') {
            $refType = 'EmpleadoTexto';
            $notas .= " para empleado: {$terceroNombre}";
        } else {
            $refType = 'Tercero';
            $notas .= " para tercero: {$terceroNombre}";
        }

        if ($labor) {
            $notas .= " | Labor: {$labor}";
        }

        return [$refType, $refId, $notas];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{registro: RegistroCombustible, inventory_adjusted: bool}
     */
    public function updateRegistro(RegistroCombustible|int $registro, array $data, ?int $actorId): array
    {
        $registroId = $registro instanceof RegistroCombustible
            ? (int) $registro->getKey()
            : $registro;

        unset($data['transaccion_id'], $data['usuario_id']);

        return DB::transaction(function () use ($registroId, $data, $actorId): array {
            $lockedRegistro = RegistroCombustible::query()
                ->whereKey($registroId)
                ->lockForUpdate()
                ->firstOrFail();

            $linkedOutput = $this->lockLinkedOutput($lockedRegistro);
            $expectedType = (string) ($data['tipo_combustible'] ?? $lockedRegistro->tipo_combustible);
            $expectedQuantity = $this->normalizeQuantity(
                $data['cantidad_galones'] ?? $lockedRegistro->cantidad_galones
            );
            $resolvedProduct = $this->resolveCombustibleProducto($expectedType);

            if (! $resolvedProduct) {
                $this->failValidation(
                    'tipo_combustible',
                    "No se encontró el producto de combustible ({$expectedType}). Verifique el inventario."
                );
            }

            $productIds = array_values(array_unique([
                (int) $linkedOutput->producto_id,
                (int) $resolvedProduct->producto_id,
            ]));

            $lockedProducts = Producto::query()
                ->whereIn('producto_id', $productIds)
                ->orderBy('producto_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('producto_id');

            $expectedProduct = $lockedProducts->get((int) $resolvedProduct->producto_id);

            if (! $expectedProduct || ! $lockedProducts->has((int) $linkedOutput->producto_id)) {
                $this->failValidation('tipo_combustible', 'No fue posible resolver el producto enlazado al tanqueo.');
            }

            $inventoryAdjusted = (int) $linkedOutput->producto_id !== (int) $expectedProduct->producto_id
                || ! $this->quantitiesMatch($linkedOutput->transaccion_cantidad, $expectedQuantity);

            if (! $inventoryAdjusted) {
                $lockedRegistro->fill($data);
                $lockedRegistro->save();

                return [
                    'registro' => $lockedRegistro->fresh(['vehiculo', 'empleado', 'usuario']),
                    'inventory_adjusted' => false,
                ];
            }

            $outputBodegaId = $this->resolveOutputBodegaId(
                $linkedOutput,
                $expectedProduct,
                $expectedQuantity
            );
            $auditUserId = $actorId ?? $linkedOutput->usuario_id ?? $lockedRegistro->usuario_id;
            [$referenceType, $referenceId] = $this->resolveAuditReference($linkedOutput, $lockedRegistro);

            TransaccionInventario::create([
                'reverses_transaction_id' => $linkedOutput->transaccion_id,
                'producto_id' => $linkedOutput->producto_id,
                'bodega_id' => $linkedOutput->bodega_id,
                'usuario_id' => $auditUserId,
                'transaccion_tipo' => 'ingreso',
                'transaccion_cantidad' => $linkedOutput->transaccion_cantidad,
                'transaccion_motivo' => self::CORRECTION_REVERSAL_REASON,
                'transaccion_referencia_type' => $referenceType,
                'transaccion_referencia_id' => $referenceId,
                'transaccion_notas' => $this->buildAuditNotes(
                    $lockedRegistro,
                    $linkedOutput,
                    'Contraasiento exacto de la salida enlazada por corrección del tanqueo.'
                ),
            ]);

            [$generatedReferenceType, $generatedReferenceId, $destinationNotes] = $this->buildReferencia(
                (string) ($data['tipo_destino'] ?? $lockedRegistro->tipo_destino),
                $this->nullableInt($data['vehiculo_id'] ?? $lockedRegistro->vehiculo_id),
                $data['tercero_nombre'] ?? $lockedRegistro->tercero_nombre,
                $data['labor'] ?? $lockedRegistro->labor,
            );

            $correctedOutput = TransaccionInventario::create([
                'producto_id' => $expectedProduct->producto_id,
                'bodega_id' => $outputBodegaId,
                'usuario_id' => $auditUserId,
                'transaccion_tipo' => 'salida',
                'transaccion_cantidad' => $expectedQuantity,
                'transaccion_motivo' => self::CORRECTED_OUTPUT_REASON,
                'transaccion_referencia_type' => $generatedReferenceType ?? $referenceType,
                'transaccion_referencia_id' => $generatedReferenceType !== null
                    ? $generatedReferenceId
                    : $referenceId,
                'transaccion_notas' => $this->buildAuditNotes(
                    $lockedRegistro,
                    $linkedOutput,
                    "{$destinationNotes}. Nueva salida corregida."
                ),
            ]);

            $lockedRegistro->fill($data);
            $lockedRegistro->transaccion_id = $correctedOutput->transaccion_id;
            $lockedRegistro->save();

            return [
                'registro' => $lockedRegistro->fresh(['vehiculo', 'empleado', 'usuario']),
                'inventory_adjusted' => true,
            ];
        });
    }

    public function destroyRegistro(RegistroCombustible|int $registro, ?int $actorId): bool
    {
        $registroId = $registro instanceof RegistroCombustible
            ? (int) $registro->getKey()
            : $registro;

        return DB::transaction(function () use ($registroId, $actorId): bool {
            $lockedRegistro = RegistroCombustible::query()
                ->whereKey($registroId)
                ->lockForUpdate()
                ->firstOrFail();
            $linkedOutput = $this->lockLinkedOutput($lockedRegistro);

            $linkedProduct = Producto::query()
                ->whereKey($linkedOutput->producto_id)
                ->lockForUpdate()
                ->first();

            if (! $linkedProduct) {
                $this->failValidation('transaccion_id', 'El producto de la transacción enlazada no existe.');
            }

            if ($linkedOutput->bodega_id !== null) {
                Bodega::query()
                    ->whereKey($linkedOutput->bodega_id)
                    ->lockForUpdate()
                    ->first();

                BodegaProducto::query()
                    ->where('bodega_id', $linkedOutput->bodega_id)
                    ->where('producto_id', $linkedOutput->producto_id)
                    ->lockForUpdate()
                    ->first();
            }

            [$referenceType, $referenceId] = $this->resolveAuditReference($linkedOutput, $lockedRegistro);

            TransaccionInventario::create([
                'reverses_transaction_id' => $linkedOutput->transaccion_id,
                'producto_id' => $linkedOutput->producto_id,
                'bodega_id' => $linkedOutput->bodega_id,
                'usuario_id' => $actorId ?? $linkedOutput->usuario_id ?? $lockedRegistro->usuario_id,
                'transaccion_tipo' => 'ingreso',
                'transaccion_cantidad' => $linkedOutput->transaccion_cantidad,
                'transaccion_motivo' => self::DELETION_REVERSAL_REASON,
                'transaccion_referencia_type' => $referenceType,
                'transaccion_referencia_id' => $referenceId,
                'transaccion_notas' => $this->buildAuditNotes(
                    $lockedRegistro,
                    $linkedOutput,
                    'Contraasiento exacto de la salida enlazada por eliminación del tanqueo.'
                ),
            ]);

            $lockedRegistro->delete();

            return true;
        });
    }

    private function lockLinkedOutput(RegistroCombustible $registro): TransaccionInventario
    {
        if (! $registro->transaccion_id) {
            $this->failValidation('transaccion_id', 'El tanqueo no tiene una transacción de inventario enlazada.');
        }

        $transaction = TransaccionInventario::query()
            ->whereKey($registro->transaccion_id)
            ->lockForUpdate()
            ->first();

        if (! $transaction) {
            $this->failValidation('transaccion_id', 'La transacción de inventario enlazada no existe.');
        }

        if (strtolower((string) $transaction->transaccion_tipo) !== 'salida') {
            $this->failValidation('transaccion_id', 'La transacción enlazada debe ser una salida de inventario.');
        }

        $sharedByAnotherFuelRecord = RegistroCombustible::query()
            ->where('transaccion_id', $transaction->transaccion_id)
            ->where('registro_id', '!=', $registro->registro_id)
            ->lockForUpdate()
            ->exists();

        if ($sharedByAnotherFuelRecord) {
            $this->failValidation(
                'transaccion_id',
                'La transacción enlazada está compartida por más de un tanqueo y requiere revisión manual.'
            );
        }

        $existingReversal = TransaccionInventario::query()
            ->where('reverses_transaction_id', $transaction->transaccion_id)
            ->lockForUpdate()
            ->exists();

        if ($existingReversal) {
            $this->failValidation(
                'transaccion_id',
                'La transacción enlazada ya tiene un contraasiento y no puede revertirse nuevamente.'
            );
        }

        return $transaction;
    }

    private function resolveOutputBodegaId(
        TransaccionInventario $linkedOutput,
        Producto $expectedProduct,
        float $expectedQuantity
    ): int {
        $sameProduct = (int) $linkedOutput->producto_id === (int) $expectedProduct->producto_id;
        $globalAvailable = (float) $expectedProduct->producto_stock_actual
            + ($sameProduct ? (float) $linkedOutput->transaccion_cantidad : 0.0);

        if ($globalAvailable + 0.00001 < $expectedQuantity) {
            $this->failValidation(
                'cantidad_galones',
                "Stock global insuficiente para la corrección. Disponible: {$globalAvailable} galones."
            );
        }

        $principalBodegaId = Bodega::query()
            ->where('tipo', 'estandar')
            ->orderBy('bodega_id')
            ->value('bodega_id')
            ?? Bodega::query()->orderBy('bodega_id')->value('bodega_id');

        $candidateIds = array_values(array_unique(array_filter([
            $linkedOutput->bodega_id !== null ? (int) $linkedOutput->bodega_id : null,
            $principalBodegaId !== null ? (int) $principalBodegaId : null,
        ], static fn (?int $id): bool => $id !== null)));

        if ($candidateIds === []) {
            $this->failValidation('bodega_id', 'No existe una bodega principal para registrar la salida corregida.');
        }

        $existingBodegaIds = Bodega::query()
            ->whereIn('bodega_id', $candidateIds)
            ->orderBy('bodega_id')
            ->lockForUpdate()
            ->pluck('bodega_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $bodegaStocks = BodegaProducto::query()
            ->whereIn('bodega_id', $existingBodegaIds)
            ->where('producto_id', $expectedProduct->producto_id)
            ->orderBy('bodega_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('bodega_id');

        foreach ($candidateIds as $candidateId) {
            if (! in_array($candidateId, $existingBodegaIds, true)) {
                continue;
            }

            $available = (float) ($bodegaStocks->get($candidateId)?->cantidad ?? 0);

            if ($sameProduct && (int) $linkedOutput->bodega_id === $candidateId) {
                $available += (float) $linkedOutput->transaccion_cantidad;
            }

            if ($available + 0.00001 >= $expectedQuantity) {
                return $candidateId;
            }
        }

        $this->failValidation(
            'cantidad_galones',
            'Stock insuficiente en la bodega original y en la bodega principal para la corrección.'
        );
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function resolveAuditReference(
        TransaccionInventario $linkedOutput,
        RegistroCombustible $registro
    ): array {
        if ($linkedOutput->transaccion_referencia_type !== null) {
            return [
                (string) $linkedOutput->transaccion_referencia_type,
                $this->nullableInt($linkedOutput->transaccion_referencia_id),
            ];
        }

        return ['RegistroCombustible', (int) $registro->registro_id];
    }

    private function buildAuditNotes(
        RegistroCombustible $registro,
        TransaccionInventario $linkedOutput,
        string $detail
    ): string {
        $originalReason = trim((string) $linkedOutput->transaccion_motivo);
        $originalReference = trim((string) $linkedOutput->transaccion_referencia_type);
        $originalReferenceId = $linkedOutput->transaccion_referencia_id;

        return implode(' | ', array_filter([
            "Tanqueo #{$registro->registro_id}",
            "Transacción origen #{$linkedOutput->transaccion_id}",
            $originalReason !== '' ? "Motivo origen: {$originalReason}" : null,
            $originalReference !== ''
                ? "Referencia origen: {$originalReference}".($originalReferenceId !== null ? " #{$originalReferenceId}" : '')
                : null,
            $detail,
        ]));
    }

    private function quantitiesMatch(float|int|string $left, float|int|string $right): bool
    {
        return abs($this->normalizeQuantity($left) - $this->normalizeQuantity($right)) < 0.005;
    }

    private function normalizeQuantity(float|int|string $quantity): float
    {
        return round((float) $quantity, 2);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function failValidation(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
