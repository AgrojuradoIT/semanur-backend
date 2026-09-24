<?php

namespace App\Services\Inventory\Orders\Ocr;

use App\DTO\InventoryOrders\DecimalQuantity;
use Illuminate\Support\Str;

/**
 * Transforma la lectura cruda del OCR a un contrato estable y validado
 * (plan sección 4). Es una función pura: no toca base de datos ni ledger.
 *
 * Reglas aplicadas:
 * - Las cantidades viajan como strings decimales canónicos validados con el DTO
 *   U1 (DecimalQuantity); nunca ints/floats.
 * - NUNCA se autocorrigen códigos: O/0 e I/1 se conservan y solo generan
 *   advertencia para selección humana.
 * - Las descripciones multilínea de una misma celda se unen.
 * - Las filas de captura vacías y las filas de cabecera/contacto/totales/
 *   controles se excluyen y se registran en ignored_rows con motivo.
 * - Separadores ambiguos (1,234) no se interpretan: advertencia bloqueante.
 * - No se asocian productos por nombre ni por similitud: eso pertenece a la
 *   revisión humana (U5).
 */
final class SiigoScreenshotMapper
{
    public const SCHEMA_VERSION = 1;

    public const MAPPER_VERSION = 'siigo-mapper-1.0.0';

    /** @var list<string> */
    private const ETIQUETAS_SKU = ['buscar', 'codigo', 'cod', 'referencia', 'ref', 'sku', 'item'];

    /** @var list<string> */
    private const ETIQUETAS_DESCRIPCION = ['descripcion', 'detalle', 'nombre'];

    /** @var list<string> */
    private const ETIQUETAS_CANTIDAD = ['cant', 'cantidad', 'unidades', 'und', 'un'];

    /** @var list<string> */
    private const ETIQUETAS_PROVEEDOR = ['proveedor', 'proveedores', 'senor', 'senores', 'senor(es)', 'senora', 'senoras', 'cliente'];

    /**
     * @param  array<string, mixed>  $crudo  Estructura del proveedor (ver PedidoOcrProvider).
     * @param  array<string, mixed>  $contexto  documento_id, documento_revision, pedido_id, provider, provider_version.
     * @return array<string, mixed> Contrato sección 4 (+ mapper_version, requires_human_revision).
     */
    public function mapear(array $crudo, array $contexto = []): array
    {
        $paginas = $this->normalizarPaginas($crudo);
        $deteccion = $this->detectarCabecera($paginas);
        $columnas = $deteccion['columnas'] ?? null;

        $proveedor = null;
        $lineas = [];
        $ignoradas = [];
        $warnings = [];
        $vistaCabecera = false;

        if ($columnas === null) {
            $warnings[] = $this->warning(
                'cabecera_tabla_no_detectada',
                'No se detectó la cabecera de la tabla de productos; no se infieren columnas ni filas de producto.',
                true,
            );
        }

        foreach ($paginas as $pagina) {
            $numeroPagina = $pagina['page_number'];

            foreach ($pagina['rows'] as $fila) {
                $numeroFila = $fila['row'];
                $celdas = $fila['cells'];
                $texto = $this->textoDeFila($celdas);

                if ($deteccion !== null
                    && $numeroPagina === $deteccion['page']
                    && $numeroFila === $deteccion['row']) {
                    $ignoradas[] = $this->ignorada($numeroPagina, $numeroFila, 'cabecera_tabla', $texto);
                    $vistaCabecera = true;

                    continue;
                }

                if ($this->esCabeceraTabla($celdas)) {
                    $ignoradas[] = $this->ignorada($numeroPagina, $numeroFila, 'cabecera_tabla_repetida', $texto);
                    $vistaCabecera = true;

                    continue;
                }

                $etiquetaProveedor = $this->detectarProveedor($celdas);

                if ($etiquetaProveedor !== null) {
                    if ($proveedor === null && $etiquetaProveedor['valor'] !== null) {
                        $proveedor = [
                            'raw' => $etiquetaProveedor['valor'],
                            'value' => $etiquetaProveedor['valor'],
                            'confidence' => $etiquetaProveedor['confidence'],
                        ];
                    }

                    $ignoradas[] = $this->ignorada($numeroPagina, $numeroFila, 'proveedor', $texto);

                    continue;
                }

                // FIX-3 (#2432): una fila con contexto de producto (cantidad
                // más SKU o descripción en sus columnas) nunca se descarta
                // como totales/empresa/contacto: "ACEITE TOTAL 15W40" o
                // "LIMPIADOR DE CONTACTO" son productos, no rótulos.
                $tieneContextoProducto = $this->tieneContextoProducto($celdas, $columnas, $vistaCabecera);

                if (! $tieneContextoProducto && $this->esFilaTotales($texto)) {
                    $ignoradas[] = $this->ignorada($numeroPagina, $numeroFila, 'totales', $texto);

                    continue;
                }

                if (! $tieneContextoProducto && $this->esFilaEmpresa($texto)) {
                    $ignoradas[] = $this->ignorada($numeroPagina, $numeroFila, 'encabezado_empresa', $texto);

                    continue;
                }

                if (! $tieneContextoProducto && $this->esFilaContacto($texto)) {
                    $ignoradas[] = $this->ignorada($numeroPagina, $numeroFila, 'contacto', $texto);

                    continue;
                }

                if ($this->esFilaControl($celdas)) {
                    $ignoradas[] = $this->ignorada($numeroPagina, $numeroFila, 'control', $texto);

                    continue;
                }

                if (! $vistaCabecera || $columnas === null) {
                    $ignoradas[] = $this->ignorada($numeroPagina, $numeroFila, 'contexto_no_producto', $texto);

                    continue;
                }

                $sku = $celdas[$columnas['sku']] ?? null;
                $descripcion = $celdas[$columnas['descripcion']] ?? null;
                $cantidad = $celdas[$columnas['cantidad']] ?? null;

                $skuRaw = $sku !== null ? trim($sku['text']) : '';
                $descripcionRaw = $descripcion !== null ? trim($descripcion['text']) : '';
                $cantidadRaw = $cantidad !== null ? trim($cantidad['text']) : '';

                $skuNormalizado = mb_strtolower($this->etiquetaNormalizada($skuRaw));
                $esFilaBuscarVacia = ($skuNormalizado === 'buscar' && $descripcionRaw === '');

                if (($skuRaw === '' && $descripcionRaw === '') || $esFilaBuscarVacia) {
                    $motivo = ($cantidadRaw !== '' || $esFilaBuscarVacia) ? 'fila_captura_vacia' : 'fila_vacia';

                    // FIX-3 (#2432): una fila que se iba a descartar en silencio
                    // pero trae texto fuera de las columnas mapeadas (outlier
                    // geométrico del puente) o una "cantidad" que no es número
                    // exige revisión humana en vez de perderse sin rastro.
                    // No se inventa fusión: solo se marca para revisión.
                    if (! $esFilaBuscarVacia
                        && $this->esPosibleContinuacionDesalineada($celdas, $columnas, $cantidadRaw)) {
                        $warnings[] = $this->warning(
                            'fila_posible_continuacion',
                            'La fila no tiene código ni descripción pero trae texto fuera de las columnas de la tabla; podría ser una continuación desalineada y requiere revisión humana.',
                            true,
                            $numeroPagina,
                            $numeroFila,
                            mb_substr($texto, 0, 200),
                        );
                    }

                    $ignoradas[] = $this->ignorada(
                        $numeroPagina,
                        $numeroFila,
                        $motivo,
                        $texto,
                    );

                    continue;
                }

                $lineas[] = $this->construirLinea(
                    $numeroPagina,
                    $numeroFila,
                    $skuRaw,
                    $sku,
                    $descripcionRaw,
                    $descripcion,
                    $cantidadRaw,
                    $cantidad,
                );
            }
        }

        if ($proveedor === null) {
            $warnings[] = $this->warning(
                'proveedor_ausente',
                'No se detectó el proveedor en la captura; se requiere completarlo o aportar nueva evidencia.',
                true,
            );
        }

        if ($lineas === []) {
            $warnings[] = $this->warning(
                'sin_lineas_detectadas',
                'No se detectaron líneas de producto legibles en la captura.',
                true,
            );
        }

        $requiereRevision = $this->requiereRevision($warnings, $lineas);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'document_id' => (int) ($contexto['documento_id'] ?? 0),
            'document_revision' => (int) ($contexto['documento_revision'] ?? 1),
            'provider' => (string) ($contexto['provider'] ?? ($crudo['provider'] ?? 'desconocido')),
            'provider_version' => $contexto['provider_version'] ?? ($crudo['provider_version'] ?? null),
            'mapper_version' => self::MAPPER_VERSION,
            'supplier' => $proveedor,
            'lines' => $lineas,
            'ignored_rows' => $ignoradas,
            'warnings' => $warnings,
            'requires_human_revision' => $requiereRevision,
        ];
    }

    /**
     * @param  array<string, mixed>  $sku  Celda original (texto completo para raw).
     * @param  array<string, mixed>  $descripcion
     * @param  array<string, mixed>  $cantidad
     * @return array<string, mixed>
     */
    private function construirLinea(
        int $pagina,
        int $fila,
        string $skuRaw,
        ?array $sku,
        string $descripcionRaw,
        ?array $descripcion,
        string $cantidadRaw,
        ?array $cantidad,
    ): array {
        $warnings = [];

        $skuValor = $skuRaw !== '' ? $this->unirMultilinea($skuRaw) : null;
        $descripcionValor = $descripcionRaw !== '' ? $this->unirMultilinea($descripcionRaw) : null;

        if ($skuValor === null) {
            $warnings[] = $this->warning('sku_ausente', 'La fila no tiene código legible; requiere completarse antes de vincular un producto.', true, $pagina, $fila, null);
        } elseif (preg_match('/[A-Za-z]/', $skuValor) === 1 && preg_match('/0/', $skuValor) === 1) {
            $warnings[] = $this->warning(
                'sku_posible_confusion_o_cero',
                'El código contiene 0 y letras: podría ser O o 0. No se autocorrige; requiere selección humana.',
                false,
                $pagina,
                $fila,
                $skuValor,
            );
        }

        if ($skuValor !== null
            && preg_match('/[A-Za-z]/', $skuValor) === 1
            && preg_match('/[1Il]/', $skuValor) === 1) {
            $warnings[] = $this->warning(
                'sku_posible_confusion_i_uno',
                'El código contiene 1/I/l: la lectura podría diferir del original. No se autocorrige; requiere revisión humana.',
                false,
                $pagina,
                $fila,
                $skuValor,
            );
        }

        if ($descripcionValor === null) {
            $warnings[] = $this->warning('descripcion_ausente', 'La fila no tiene descripción legible.', true, $pagina, $fila, null);
        }

        [$cantidadValor, $warningsCantidad] = $this->parsearCantidad($cantidadRaw, $pagina, $fila);
        $warnings = array_merge($warnings, $warningsCantidad);

        return [
            'candidate_id' => (string) Str::uuid(),
            'source_page' => $pagina,
            'source_row' => $fila,
            'sku' => [
                'raw' => $skuRaw !== '' ? $skuRaw : null,
                'value' => $skuValor,
                'confidence' => $sku['confidence'] ?? null,
            ],
            'description' => [
                'raw' => $descripcionRaw !== '' ? $descripcionRaw : null,
                'value' => $descripcionValor,
                'confidence' => $descripcion['confidence'] ?? null,
            ],
            'expected_quantity' => [
                'raw' => $cantidadRaw !== '' ? $cantidadRaw : null,
                'value' => $cantidadValor,
                'confidence' => $cantidad['confidence'] ?? null,
            ],
            'bounding_boxes' => [
                'sku' => $sku['box'] ?? null,
                'description' => $descripcion['box'] ?? null,
                'expected_quantity' => $cantidad['box'] ?? null,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{0: string|null, 1: list<array<string, mixed>>}
     */
    private function parsearCantidad(string $raw, int $pagina, int $fila): array
    {
        $texto = trim($raw);

        if ($texto === '') {
            return [null, [$this->warning('cantidad_ilegible', 'La cantidad de la fila está vacía o no se pudo leer.', true, $pagina, $fila, null)]];
        }

        if (preg_match('/[eE]/', $texto) === 1) {
            return [null, [$this->warning('cantidad_notacion_exponencial', 'La cantidad usa notación exponencial y se rechaza; requiere revisión.', true, $pagina, $fila, $texto)]];
        }

        if (preg_match('/^[+\-]/', $texto) === 1) {
            return [null, [$this->warning('cantidad_signo_invalido', 'La cantidad tiene signo; no se aceptan negativos ni positivos explícitos.', true, $pagina, $fila, $texto)]];
        }

        if (preg_match('/^\d+,\d{3}(,\d{3})*$/', $texto) === 1
            || preg_match('/^\d{1,3}(\.\d{3})+$/', $texto) === 1) {
            return [null, [$this->warning(
                'cantidad_separador_ambiguo',
                'El separador de miles es ambiguo (por ejemplo 1,234); requiere confirmación humana.',
                true,
                $pagina,
                $fila,
                $texto,
            )]];
        }

        $canonico = DecimalQuantity::tryNormalize($texto, true);

        if ($canonico === null) {
            if (preg_match('/^\d+[.,]\d{3,}$/', $texto) === 1) {
                return [null, [$this->warning(
                    'cantidad_precision_excedida',
                    'La cantidad supera la precisión decimal del ledger (2 decimales); requiere revisión.',
                    true,
                    $pagina,
                    $fila,
                    $texto,
                )]];
            }

            return [null, [$this->warning('cantidad_invalida', 'La cantidad no es un decimal exacto válido; requiere revisión.', true, $pagina, $fila, $texto)]];
        }

        if ($canonico === '0.00') {
            return [null, [$this->warning('cantidad_cero', 'La cantidad leída es cero; no se crea una cantidad esperada.', true, $pagina, $fila, $texto)]];
        }

        return [$canonico, []];
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     * @param  list<array<string, mixed>>  $lineas
     */
    private function requiereRevision(array $warnings, array $lineas): bool
    {
        foreach ($warnings as $warning) {
            if (($warning['blocking'] ?? false) === true) {
                return true;
            }
        }

        foreach ($lineas as $linea) {
            foreach (($linea['warnings'] ?? []) as $warning) {
                if (($warning['blocking'] ?? false) === true) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $paginas
     * @return array{columnas: array{sku: int, descripcion: int, cantidad: int}, page: int, row: int}|null
     */
    private function detectarCabecera(array $paginas): ?array
    {
        foreach ($paginas as $pagina) {
            foreach ($pagina['rows'] as $fila) {
                $columnas = $this->columnasDeCabecera($fila['cells']);

                if ($columnas !== null) {
                    return ['columnas' => $columnas, 'page' => $pagina['page_number'], 'row' => $fila['row']];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $celdas
     * @return array{sku: int, descripcion: int, cantidad: int}|null
     */
    private function columnasDeCabecera(array $celdas): ?array
    {
        $sku = null;
        $descripcion = null;
        $cantidad = null;
        $producto = null;

        foreach ($celdas as $columna => $celda) {
            $etiqueta = $this->etiquetaNormalizada((string) ($celda['text'] ?? ''));

            if ($etiqueta === '') {
                continue;
            }

            if ($sku === null && in_array($etiqueta, self::ETIQUETAS_SKU, true)) {
                $sku = (int) $columna;
            }

            if ($descripcion === null && in_array($etiqueta, self::ETIQUETAS_DESCRIPCION, true)) {
                $descripcion = (int) $columna;
            }

            if ($cantidad === null && in_array($etiqueta, self::ETIQUETAS_CANTIDAD, true)) {
                $cantidad = (int) $columna;
            }

            if ($producto === null && in_array($etiqueta, ['producto', 'productos'], true)) {
                $producto = (int) $columna;
            }
        }

        if ($sku === null && $descripcion !== null && $producto !== null) {
            $sku = $producto;
        } elseif ($descripcion === null && $sku !== null && $producto !== null) {
            $descripcion = $producto;
        }

        if ($sku === null || $descripcion === null || $cantidad === null) {
            return null;
        }

        if (count(array_unique([$sku, $descripcion, $cantidad])) < 3) {
            return null;
        }

        return ['sku' => $sku, 'descripcion' => $descripcion, 'cantidad' => $cantidad];
    }

    /**
     * @param  array<int, array<string, mixed>>  $celdas
     */
    private function esCabeceraTabla(array $celdas): bool
    {
        return $this->columnasDeCabecera($celdas) !== null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $celdas
     * @return array{valor: string|null, confidence: float|null}|null
     */
    private function detectarProveedor(array $celdas): ?array
    {
        foreach ($celdas as $columna => $celda) {
            $texto = trim((string) ($celda['text'] ?? ''));

            if ($texto === '') {
                continue;
            }

            $partes = explode(':', $texto, 2);
            $etiqueta = $this->etiquetaNormalizada($partes[0] ?? '');

            if (! in_array($etiqueta, self::ETIQUETAS_PROVEEDOR, true)) {
                continue;
            }

            $resto = trim($partes[1] ?? '');

            if ($resto !== '') {
                return ['valor' => $resto, 'confidence' => $celda['confidence'] ?? null];
            }

            foreach ($celdas as $otraColumna => $otraCelda) {
                if ((int) $otraColumna <= (int) $columna) {
                    continue;
                }

                $otro = trim((string) ($otraCelda['text'] ?? ''));

                if ($otro !== '') {
                    return ['valor' => $otro, 'confidence' => $otraCelda['confidence'] ?? null];
                }
            }

            return ['valor' => null, 'confidence' => null];
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $celdas
     */
    private function textoDeFila(array $celdas): string
    {
        $textos = [];

        foreach ($celdas as $celda) {
            $texto = trim((string) ($celda['text'] ?? ''));

            if ($texto !== '') {
                $textos[] = $this->unirMultilinea($texto);
            }
        }

        return implode(' ', $textos);
    }

    private function esFilaTotales(string $texto): bool
    {
        return $texto !== ''
            && preg_match('/\b(sub\s*total|total|iva|rete\s*fuente|retefuente|retenci[oó]n|descuento|flete|anticipo|saldo|abono)\b/iu', $texto) === 1;
    }

    /**
     * Indica si la fila trae contexto de producto en las columnas de la
     * tabla: cantidad legible más SKU o descripción. La cantidad es
     * obligatoria: un rótulo de totales ("SUBTOTAL") puede caer en la
     * columna SKU según el layout del proveedor, pero nunca trae Cant;
     * sin cantidad no hay línea pedible y el rótulo genuino se sigue
     * filtrando. Solo se usa para no confundir un producto
     * ("ACEITE TOTAL 15W40") con un rótulo de totales/contacto/empresa.
     *
     * @param  array<int, array<string, mixed>>  $celdas
     * @param  array{sku: int, descripcion: int, cantidad: int}|null  $columnas
     */
    private function tieneContextoProducto(array $celdas, ?array $columnas, bool $vistaCabecera): bool
    {
        if (! $vistaCabecera || $columnas === null) {
            return false;
        }

        $sku = $celdas[$columnas['sku']] ?? null;
        $descripcion = $celdas[$columnas['descripcion']] ?? null;
        $cantidad = $celdas[$columnas['cantidad']] ?? null;

        if (trim((string) ($cantidad['text'] ?? '')) === '') {
            return false;
        }

        return trim((string) ($sku['text'] ?? '')) !== ''
            || trim((string) ($descripcion['text'] ?? '')) !== '';
    }

    /**
     * Detecta una posible continuación desalineada en una fila que se iba a
     * descartar como vacía: texto en columnas fuera de las mapeadas (el
     * puente creó una columna nueva por el desplazamiento en x) o una
     * "cantidad" que no es un número (texto caído en la columna Cant).
     *
     * @param  array<int, array<string, mixed>>  $celdas
     * @param  array{sku: int, descripcion: int, cantidad: int}|null  $columnas
     */
    private function esPosibleContinuacionDesalineada(array $celdas, ?array $columnas, string $cantidadRaw): bool
    {
        if ($columnas !== null) {
            $mapeadas = [(int) $columnas['sku'], (int) $columnas['descripcion'], (int) $columnas['cantidad']];

            foreach ($celdas as $columna => $celda) {
                if (in_array((int) $columna, $mapeadas, true)) {
                    continue;
                }

                if (trim((string) ($celda['text'] ?? '')) !== '') {
                    return true;
                }
            }
        }

        return $cantidadRaw !== '' && DecimalQuantity::tryNormalize($cantidadRaw, true) === null;
    }

    private function esFilaContacto(string $texto): bool
    {
        return $texto !== ''
            && preg_match('/\b(nit|c[eé]dula|direcci[oó]n|tel[eé]fono|celular|correo|e-?mail|ciudad|contacto)\b/iu', $texto) === 1;
    }

    private function esFilaEmpresa(string $texto): bool
    {
        return $texto !== ''
            && preg_match('/\b(semanur|s\.?a\.?s\.?|sas|ltda\.?|limitada)\b/iu', $texto) === 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $celdas
     */
    private function esFilaControl(array $celdas): bool
    {
        $textos = [];

        foreach ($celdas as $celda) {
            $texto = trim((string) ($celda['text'] ?? ''));

            if ($texto !== '') {
                $textos[] = mb_strtolower($texto);
            }
        }

        if ($textos === []) {
            return false;
        }

        foreach ($textos as $texto) {
            if (preg_match('/^(guardar|cancelar|buscar|agregar|imprimir|nuevo|eliminar|actualizar|cerrar|volver|siguiente|anterior)$/iu', $texto) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function unirMultilinea(string $texto): string
    {
        $partes = preg_split('/\R/u', $texto) ?: [$texto];
        $limpias = [];

        foreach ($partes as $parte) {
            $parte = trim(preg_replace('/\s+/u', ' ', $parte) ?? '');

            if ($parte !== '') {
                $limpias[] = $parte;
            }
        }

        return implode(' ', $limpias);
    }

    private function etiquetaNormalizada(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = Str::ascii($texto);
        $texto = trim($texto, ": \t\n\r\0\x0B");

        return preg_replace('/\s+/', ' ', $texto) ?? '';
    }

    /**
     * @return array{source_page: int, source_row: int, reason: string, raw_text: string}
     */
    private function ignorada(int $pagina, int $fila, string $motivo, string $texto): array
    {
        return [
            'source_page' => $pagina,
            'source_row' => $fila,
            'reason' => $motivo,
            'raw_text' => mb_substr($texto, 0, 200),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function warning(
        string $code,
        string $message,
        bool $blocking,
        ?int $pagina = null,
        ?int $fila = null,
        ?string $raw = null,
    ): array {
        return [
            'code' => $code,
            'message' => $message,
            'blocking' => $blocking,
            'source' => $pagina !== null ? ['page' => $pagina, 'row' => $fila] : null,
            'raw' => $raw,
        ];
    }

    /**
     * @param  array<string, mixed>  $crudo
     * @return list<array{page_number: int, rows: list<array{row: int, cells: array<int, array{text: string, confidence: float|null, box: array<string, mixed>|null}>}>}>
     */
    private function normalizarPaginas(array $crudo): array
    {
        $paginas = [];

        foreach ((array) ($crudo['pages'] ?? []) as $pagina) {
            if (! is_array($pagina)) {
                continue;
            }

            $filas = [];

            foreach ((array) ($pagina['rows'] ?? []) as $fila) {
                if (! is_array($fila)) {
                    continue;
                }

                $celdas = [];

                foreach ((array) ($fila['cells'] ?? []) as $celda) {
                    if (! is_array($celda)) {
                        continue;
                    }

                    $columna = (int) ($celda['column'] ?? (count($celdas) + 1));
                    $confidence = $celda['confidence'] ?? null;
                    $confidence = is_numeric($confidence) ? (float) $confidence : null;
                    $box = is_array($celda['bounding_box'] ?? null) ? $celda['bounding_box'] : null;
                    $texto = (string) ($celda['text'] ?? '');

                    if (isset($celdas[$columna])) {
                        $prev = $celdas[$columna];
                        $celdas[$columna] = [
                            'text' => trim($prev['text'].' '.$texto),
                            'confidence' => $confidence !== null
                                ? ($prev['confidence'] !== null ? min($prev['confidence'], $confidence) : $confidence)
                                : $prev['confidence'],
                            'box' => $this->unirCajas($prev['box'], $box),
                        ];
                    } else {
                        $celdas[$columna] = [
                            'text' => $texto,
                            'confidence' => $confidence,
                            'box' => $box,
                        ];
                    }
                }

                ksort($celdas);

                $filas[] = [
                    'row' => (int) ($fila['row'] ?? (count($filas) + 1)),
                    'cells' => $celdas,
                ];
            }

            $paginas[] = [
                'page_number' => (int) ($pagina['page_number'] ?? (count($paginas) + 1)),
                'rows' => $filas,
            ];
        }

        return $paginas;
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}|null  $a
     * @param  array{x: int, y: int, width: int, height: int}|null  $b
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    private function unirCajas(?array $a, ?array $b): ?array
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        $xMin = min($a['x'] ?? 0, $b['x'] ?? 0);
        $yMin = min($a['y'] ?? 0, $b['y'] ?? 0);
        $xMax = max(($a['x'] ?? 0) + ($a['width'] ?? 0), ($b['x'] ?? 0) + ($b['width'] ?? 0));
        $yMax = max(($a['y'] ?? 0) + ($a['height'] ?? 0), ($b['y'] ?? 0) + ($b['height'] ?? 0));

        return [
            'x' => $xMin,
            'y' => $yMin,
            'width' => max($xMax - $xMin, 1),
            'height' => max($yMax - $yMin, 1),
        ];
    }
}

