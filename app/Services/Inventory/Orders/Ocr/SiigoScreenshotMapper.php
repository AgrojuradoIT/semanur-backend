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
    private const ETIQUETAS_SKU = ['buscar', 'codigo', 'cod', 'referencia', 'ref', 'sku', 'item', 'producto'];

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
        $contactoRespaldo = null;
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

                // Guarda de contexto de producto (variante DS): una fila de datos
                // con cantidad mapeada + (sku o descripción) NUNCA se descarta
                // como totales/empresa/contacto (p. ej. "ACEITE TOTAL 15W40" o
                // "LIMPIADOR DE CONTACTO" con cantidad son productos, no
                // etiquetas). Las filas de etiqueta genuinas (sin ese contexto)
                // siguen filtrándose como antes.
                $tieneContextoProducto = $this->tieneContextoProducto($celdas, $columnas);

                if (! $tieneContextoProducto && $this->esFilaTotales($texto)) {
                    $ignoradas[] = $this->ignorada($numeroPagina, $numeroFila, 'totales', $texto);

                    continue;
                }

                if (! $tieneContextoProducto && $this->esFilaEmpresa($texto)) {
                    $ignoradas[] = $this->ignorada($numeroPagina, $numeroFila, 'encabezado_empresa', $texto);

                    continue;
                }

                if (! $tieneContextoProducto && $this->esFilaContacto($texto)) {
                    if ($contactoRespaldo === null) {
                        $contactoRespaldo = $this->extraerContactoPill($celdas);
                    }

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

                if ($skuRaw === '' && $descripcionRaw === '') {
                    $ignoradas[] = $this->ignorada(
                        $numeroPagina,
                        $numeroFila,
                        $cantidadRaw !== '' ? 'fila_captura_vacia' : 'fila_vacia',
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

        // Respaldo DS: la pastilla "Contacto:" solo aporta el nombre del
        // proveedor cuando no existe ninguna fila "Proveedores:". Nunca se
        // promueve una fila de contacto a etiqueta proveedora (riesgo de
        // capturar direcciones o datos de contacto como proveedor).
        if ($proveedor === null && ($contactoRespaldo['valor'] ?? null) !== null) {
            $proveedor = [
                'raw' => $contactoRespaldo['valor'],
                'value' => $contactoRespaldo['valor'],
                'confidence' => $contactoRespaldo['confidence'],
            ];
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
     *
     * La etiqueta `producto` (columna SKU de la variante DS) solo elige
     * columna cuando el trío completo resuelve en LA MISMA fila: una celda
     * de datos con el texto "producto" (columna Tipo) jamás basta por sí
     * sola para declarar una cabecera.
     */
    private function columnasDeCabecera(array $celdas): ?array
    {
        $sku = null;
        $descripcion = null;
        $cantidad = null;

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
     * Contexto de producto para la guarda DS: la fila trae cantidad mapeada
     * no vacía MÁS sku o descripción no vacíos en sus columnas mapeadas.
     *
     * @param  array<int, array<string, mixed>>  $celdas
     * @param  array{sku: int, descripcion: int, cantidad: int}|null  $columnas
     */
    private function tieneContextoProducto(array $celdas, ?array $columnas): bool
    {
        if ($columnas === null) {
            return false;
        }

        $celdaCantidad = $celdas[$columnas['cantidad']] ?? null;
        $cantidad = trim((string) (($celdaCantidad['text'] ?? '')));

        if ($cantidad === '') {
            return false;
        }

        $celdaSku = $celdas[$columnas['sku']] ?? null;
        $celdaDescripcion = $celdas[$columnas['descripcion']] ?? null;

        return trim((string) (($celdaSku['text'] ?? ''))) !== ''
            || trim((string) (($celdaDescripcion['text'] ?? ''))) !== '';
    }

    /**
     * Extrae el valor de una pastilla exacta "Contacto: ..." SIN promover la
     * fila a proveedora: solo sirve como respaldo cuando no hay "Proveedores:".
     *
     * @param  array<int, array<string, mixed>>  $celdas
     * @return array{valor: string|null, confidence: float|null}|null
     */
    private function extraerContactoPill(array $celdas): ?array
    {
        foreach ($celdas as $columna => $celda) {
            $texto = trim((string) ($celda['text'] ?? ''));

            if ($texto === '') {
                continue;
            }

            $partes = explode(':', $texto, 2);

            if ($this->etiquetaNormalizada($partes[0] ?? '') !== 'contacto') {
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
                    $box = $celda['bounding_box'] ?? null;

                    $celdas[$columna] = [
                        'text' => (string) ($celda['text'] ?? ''),
                        'confidence' => $confidence,
                        'box' => is_array($box) ? $box : null,
                    ];
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
}
