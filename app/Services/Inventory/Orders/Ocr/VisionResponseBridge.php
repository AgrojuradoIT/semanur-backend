<?php

namespace App\Services\Inventory\Orders\Ocr;

/**
 * Puente Vision → estructura cruda neutral (U12, decisión D2).
 *
 * Convierte una respuesta `DOCUMENT_TEXT_DETECTION` de Cloud Vision
 * (`fullTextAnnotation.pages[].blocks[].paragraphs[].words[]`, con cajas y
 * confianzas por símbolo) a la forma cruda que consume
 * `SiigoScreenshotMapper` (`pages[].rows[].cells[]`). Función pura: sin
 * base de datos, sin ledger, sin red.
 *
 * Algoritmo (detallado en docs/inventory-orders/U12-vision.md):
 * 1. Cada palabra = texto concatenado de sus símbolos, confianza = mínimo de
 *    las confianzas de sus símbolos (fallback: confianza de la palabra, si no
 *    null — nunca 1.0 inventado), caja = unión de las cajas de sus símbolos
 *    (fallback: caja de la palabra). Sin símbolos ni caja de palabra, la
 *    palabra se descarta: jamás se inventa geometría.
 * 2. Filas: se ordenan las palabras por centro-y y se agrupan por
 *    solapamiento de banda vertical con tolerancia 0.5 × altura mediana
 *    global de palabra. Las filas se ordenan de arriba hacia abajo.
 * 3. Celdas: dentro de cada fila, las palabras se ordenan de izquierda a
 *    derecha y se corta una celda nueva cuando el hueco-x supera
 *    1.5 × altura mediana de la fila. Después las celdas se alinean a
 *    columnas globales de página agrupando sus inicios-x con tolerancia
 *    1.0 × altura mediana global (single-linkage): así el `1.00` de la fila
 *    de captura vacía cae en la columna CANT y el mapper la ignora en vez
 *    de tratarla como SKU.
 *
 * Reglas preservadas:
 * - Líneas visuales distintas (p. ej. `TORNILLO` / `CORTAMALEZA`) quedan en
 *   filas SEPARADAS: la unión de descripciones multilínea pertenece al
 *   mapper, no al puente. La fila de continuación resultante exige revisión
 *   humana en el mapper (advertencia bloqueante), nunca se descarta.
 * - Ninguna inferencia semántica: la detección de cabecera sigue siendo del
 *   mapper. Las columnas son solo índices geométricos 1..N.
 * - Áreas vacías no generan filas fantasma: solo existen las filas que
 *   tienen palabras detectadas.
 */
final class VisionResponseBridge
{
    public const BRIDGE_VERSION = 'vision-bridge-1.0.0';

    /** Tolerancia vertical de agrupación de filas, en alturas medianas de palabra. */
    public const TOLERANCIA_FILA_ALTURA = 0.5;

    /** Umbral de corte de celda dentro de una fila, en alturas medianas de la fila. */
    public const UMBRAL_CELDA_ALTURA = 1.5;

    /** Tolerancia de alineación de columnas globales, en alturas medianas globales. */
    public const TOLERANCIA_COLUMNA_ALTURA = 1.0;

    /**
     * @param  array<string, mixed>  $respuestaVision  Respuesta completa de
     *     `images:annotate` (`responses[0]`) o el objeto de respuesta simple
     *     con `fullTextAnnotation`.
     * @return array{full_text: string, pages: list<array{page_number: int, width: int|null, height: int|null, rows: list<array{row: int, cells: list<array{column: int, text: string, confidence: float|null, bounding_box: array{x: int, y: int, width: int, height: int}|null}>}>}>}
     *
     * @throws OcrException terminal `respuesta_sin_geometria` cuando hay texto
     *     detectado pero sin geometría de documento para reconstruir filas.
     */
    public function convertir(array $respuestaVision): array
    {
        $respuesta = $respuestaVision;

        if (isset($respuesta['responses']) && is_array($respuesta['responses'])) {
            $respuesta = $respuesta['responses'][0] ?? [];
        }

        if (! is_array($respuesta)) {
            throw OcrException::terminal('respuesta_sin_geometria', 'La respuesta de Vision no tiene la forma esperada: sin objeto de documento.');
        }

        $completa = $respuesta['fullTextAnnotation'] ?? null;

        if (! is_array($completa)) {
            throw OcrException::terminal(
                'respuesta_sin_geometria',
                'La respuesta de Vision no trae fullTextAnnotation: no hay geometría para reconstruir filas y celdas.',
            );
        }

        $paginasVision = is_array($completa['pages'] ?? null) ? $completa['pages'] : [];
        $textoCompleto = $this->textoCompleto($respuesta);

        $paginas = [];

        foreach (array_values($paginasVision) as $indice => $paginaVision) {
            if (! is_array($paginaVision)) {
                continue;
            }

            $ancho = isset($paginaVision['width']) && is_numeric($paginaVision['width'])
                ? (int) $paginaVision['width']
                : null;
            $alto = isset($paginaVision['height']) && is_numeric($paginaVision['height'])
                ? (int) $paginaVision['height']
                : null;

            $palabras = $this->extraerPalabras($paginaVision, $ancho, $alto);

            $paginas[] = [
                'page_number' => $indice + 1,
                'width' => $ancho,
                'height' => $alto,
                'rows' => $this->construirFilas($palabras),
            ];
        }

        return [
            'full_text' => $textoCompleto,
            'pages' => $paginas,
        ];
    }

    /**
     * @param  array<string, mixed>  $respuesta
     */
    private function textoCompleto(array $respuesta): string
    {
        $anotaciones = $respuesta['textAnnotations'] ?? null;

        if (is_array($anotaciones)
            && isset($anotaciones[0]['description'])
            && is_string($anotaciones[0]['description'])) {
            return $anotaciones[0]['description'];
        }

        return '';
    }

    /**
     * Aplana bloques → párrafos → palabras a una lista con texto, confianza,
     * caja y centro geométrico.
     *
     * @param  array<string, mixed>  $paginaVision
     * @return list<array{text: string, confidence: float|null, box: array{x: int, y: int, width: int, height: int}|null, x_center: float, y_center: float, height: float}>
     */
    private function extraerPalabras(array $paginaVision, ?int $anchoPagina, ?int $altoPagina): array
    {
        $palabras = [];
        $bloques = is_array($paginaVision['blocks'] ?? null) ? $paginaVision['blocks'] : [];

        foreach ($bloques as $bloque) {
            if (! is_array($bloque)) {
                continue;
            }

            $parrafos = is_array($bloque['paragraphs'] ?? null) ? $bloque['paragraphs'] : [];

            foreach ($parrafos as $parrafo) {
                if (! is_array($parrafo)) {
                    continue;
                }

                $vocablos = is_array($parrafo['words'] ?? null) ? $parrafo['words'] : [];

                foreach ($vocablos as $vocablo) {
                    if (! is_array($vocablo)) {
                        continue;
                    }

                    $palabra = $this->normalizarPalabra($vocablo, $anchoPagina, $altoPagina);

                    if ($palabra !== null) {
                        $palabras[] = $palabra;
                    }
                }
            }
        }

        return $palabras;
    }

    /**
     * @param  array<string, mixed>  $vocablo
     * @return array{text: string, confidence: float|null, box: array{x: int, y: int, width: int, height: int}|null, x_center: float, y_center: float, height: float}|null
     */
    private function normalizarPalabra(array $vocablo, ?int $anchoPagina, ?int $altoPagina): ?array
    {
        $simbolos = is_array($vocablo['symbols'] ?? null) ? $vocablo['symbols'] : [];

        $texto = '';
        $confianza = null;
        $caja = null;

        foreach ($simbolos as $simbolo) {
            if (! is_array($simbolo)) {
                continue;
            }

            $letra = $simbolo['text'] ?? '';

            if (! is_string($letra) || $letra === '') {
                continue;
            }

            $texto .= $letra;

            $confSimbolo = $this->confianzaNumerica($simbolo['confidence'] ?? null);

            if ($confSimbolo !== null && ($confianza === null || $confSimbolo < $confianza)) {
                $confianza = $confSimbolo;
            }

            $cajaSimbolo = $this->normalizarCaja($simbolo['boundingBox'] ?? null, $anchoPagina, $altoPagina);

            if ($cajaSimbolo !== null) {
                $caja = $caja === null ? $cajaSimbolo : $this->unirCajas($caja, $cajaSimbolo);
            }
        }

        if ($texto === '') {
            return null;
        }

        if ($confianza === null) {
            $confianza = $this->confianzaNumerica($vocablo['confidence'] ?? null);
        }

        if ($caja === null) {
            $caja = $this->normalizarCaja($vocablo['boundingBox'] ?? null, $anchoPagina, $altoPagina);
        }

        if ($caja === null) {
            return null;
        }

        return [
            'text' => $texto,
            'confidence' => $confianza,
            'box' => $caja,
            'x_center' => $caja['x'] + $caja['width'] / 2,
            'y_center' => $caja['y'] + $caja['height'] / 2,
            'height' => (float) max($caja['height'], 1),
        ];
    }

    private function confianzaNumerica(mixed $valor): ?float
    {
        if (! is_numeric($valor)) {
            return null;
        }

        $confianza = (float) $valor;

        if ($confianza < 0.0 || $confianza > 1.0) {
            return null;
        }

        return $confianza;
    }

    /**
     * @param  array<string, mixed>|null  $cajaVision
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    private function normalizarCaja(mixed $cajaVision, ?int $anchoPagina, ?int $altoPagina): ?array
    {
        if (! is_array($cajaVision)) {
            return null;
        }

        $vertices = $cajaVision['vertices'] ?? null;

        if (is_array($vertices) && $vertices !== []) {
            $xs = [];
            $ys = [];

            foreach ($vertices as $vertice) {
                if (! is_array($vertice) || ! isset($vertice['x'], $vertice['y'])) {
                    continue;
                }

                if (! is_numeric($vertice['x']) || ! is_numeric($vertice['y'])) {
                    continue;
                }

                $xs[] = (float) $vertice['x'];
                $ys[] = (float) $vertice['y'];
            }

            if ($xs === [] || $ys === []) {
                return null;
            }

            return $this->cajaDesdeExtremos(min($xs), min($ys), max($xs), max($ys));
        }

        $normalizados = $cajaVision['normalizedVertices'] ?? null;

        if (is_array($normalizados) && $normalizados !== [] && $anchoPagina !== null && $altoPagina !== null) {
            $xs = [];
            $ys = [];

            foreach ($normalizados as $vertice) {
                if (! is_array($vertice) || ! isset($vertice['x'], $vertice['y'])) {
                    continue;
                }

                if (! is_numeric($vertice['x']) || ! is_numeric($vertice['y'])) {
                    continue;
                }

                $xs[] = (float) $vertice['x'] * $anchoPagina;
                $ys[] = (float) $vertice['y'] * $altoPagina;
            }

            if ($xs === [] || $ys === []) {
                return null;
            }

            return $this->cajaDesdeExtremos(min($xs), min($ys), max($xs), max($ys));
        }

        return null;
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}
     */
    private function cajaDesdeExtremos(float $xMin, float $yMin, float $xMax, float $yMax): array
    {
        $x = (int) round($xMin);
        $y = (int) round($yMin);

        return [
            'x' => $x,
            'y' => $y,
            'width' => max((int) round($xMax) - $x, 1),
            'height' => max((int) round($yMax) - $y, 1),
        ];
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}  $a
     * @param  array{x: int, y: int, width: int, height: int}  $b
     * @return array{x: int, y: int, width: int, height: int}
     */
    private function unirCajas(array $a, array $b): array
    {
        $xMin = min($a['x'], $b['x']);
        $yMin = min($a['y'], $b['y']);
        $xMax = max($a['x'] + $a['width'], $b['x'] + $b['width']);
        $yMax = max($a['y'] + $a['height'], $b['y'] + $b['height']);

        return [
            'x' => $xMin,
            'y' => $yMin,
            'width' => max($xMax - $xMin, 1),
            'height' => max($yMax - $yMin, 1),
        ];
    }

    /**
     * Agrupa palabras en filas, corta celdas por hueco-x y alinea columnas
     * globales de página.
     *
     * @param  list<array{text: string, confidence: float|null, box: array{x: int, y: int, width: int, height: int}|null, x_center: float, y_center: float, height: float}>  $palabras
     * @return list<array{row: int, cells: list<array{column: int, text: string, confidence: float|null, bounding_box: array{x: int, y: int, width: int, height: int}|null}>}>
     */
    private function construirFilas(array $palabras): array
    {
        if ($palabras === []) {
            return [];
        }

        usort($palabras, static fn (array $a, array $b): int => $a['y_center'] <=> $b['y_center'] ?: $a['x_center'] <=> $b['x_center']);

        $medianaGlobal = $this->mediana(array_map(static fn (array $p): float => $p['height'], $palabras));
        $toleranciaFila = max($medianaGlobal * self::TOLERANCIA_FILA_ALTURA, 1.0);

        $grupos = [];
        $grupoActual = [];
        $centroActual = null;

        foreach ($palabras as $palabra) {
            if ($grupoActual === [] || $centroActual === null || abs($palabra['y_center'] - $centroActual) <= $toleranciaFila) {
                $grupoActual[] = $palabra;
                $centroActual = array_sum(array_column($grupoActual, 'y_center')) / count($grupoActual);

                continue;
            }

            $grupos[] = $grupoActual;
            $grupoActual = [$palabra];
            $centroActual = $palabra['y_center'];
        }

        if ($grupoActual !== []) {
            $grupos[] = $grupoActual;
        }

        $filasProvisionales = [];

        foreach ($grupos as $grupo) {
            $celdas = $this->cortarCeldas($grupo);

            if ($celdas !== []) {
                $filasProvisionales[] = $celdas;
            }
        }

        return $this->alinearColumnas($filasProvisionales, $medianaGlobal);
    }

    /**
     * Corta una fila de palabras en celdas por huecos-x grandes.
     *
     * @param  list<array{text: string, confidence: float|null, box: array{x: int, y: int, width: int, height: int}|null, x_center: float, y_center: float, height: float}>  $grupo
     * @return list<array{text: string, confidence: float|null, box: array{x: int, y: int, width: int, height: int}|null, x_start: int}>
     */
    private function cortarCeldas(array $grupo): array
    {
        usort($grupo, static function (array $a, array $b): int {
            $comparacion = ($a['box']['x'] ?? 0) <=> ($b['box']['x'] ?? 0);

            return $comparacion === 0 ? $a['x_center'] <=> $b['x_center'] : $comparacion;
        });

        $medianaFila = $this->mediana(array_map(static fn (array $p): float => $p['height'], $grupo));
        $umbral = max($medianaFila * self::UMBRAL_CELDA_ALTURA, 1.0);

        $celdas = [];
        $actual = [];

        foreach ($grupo as $palabra) {
            $anterior = end($actual);

            if ($anterior !== false) {
                $finAnterior = $anterior['box']['x'] + $anterior['box']['width'];
                $hueco = $palabra['box']['x'] - $finAnterior;

                if ($hueco > $umbral) {
                    $celdas[] = $this->fusionarCelda($actual);
                    $actual = [];
                }
            }

            $actual[] = $palabra;
        }

        if ($actual !== []) {
            $celdas[] = $this->fusionarCelda($actual);
        }

        return $celdas;
    }

    /**
     * @param  list<array{text: string, confidence: float|null, box: array{x: int, y: int, width: int, height: int}|null, x_center: float, y_center: float, height: float}>  $palabras
     * @return array{text: string, confidence: float|null, box: array{x: int, y: int, width: int, height: int}|null, x_start: int}
     */
    private function fusionarCelda(array $palabras): array
    {
        $textos = array_map(static fn (array $p): string => $p['text'], $palabras);
        $confianza = null;

        foreach ($palabras as $palabra) {
            if ($palabra['confidence'] !== null && ($confianza === null || $palabra['confidence'] < $confianza)) {
                $confianza = $palabra['confidence'];
            }
        }

        $caja = null;

        foreach ($palabras as $palabra) {
            if ($palabra['box'] !== null) {
                $caja = $caja === null ? $palabra['box'] : $this->unirCajas($caja, $palabra['box']);
            }
        }

        return [
            'text' => implode(' ', $textos),
            'confidence' => $confianza,
            'box' => $caja,
            'x_start' => $caja !== null ? $caja['x'] : 0,
        ];
    }

    /**
     * Asigna números de columna globales por inicio-x (single-linkage).
     *
     * @param  list<list<array{text: string, confidence: float|null, box: array{x: int, y: int, width: int, height: int}|null, x_start: int}>>  $filasProvisionales
     * @return list<array{row: int, cells: list<array{column: int, text: string, confidence: float|null, bounding_box: array{x: int, y: int, width: int, height: int}|null}>}>
     */
    private function alinearColumnas(array $filasProvisionales, float $medianaGlobal): array
    {
        $tolerancia = max($medianaGlobal * self::TOLERANCIA_COLUMNA_ALTURA, 8.0);

        $inicios = [];

        foreach ($filasProvisionales as $celdas) {
            foreach ($celdas as $celda) {
                $inicios[] = $celda['x_start'];
            }
        }

        sort($inicios);

        $clusters = [];

        foreach ($inicios as $inicio) {
            $ultimo = end($clusters);

            if ($ultimo === false || ($inicio - end($ultimo)) > $tolerancia) {
                $clusters[] = [$inicio];

                continue;
            }

            $clusters[count($clusters) - 1][] = $inicio;
        }

        $representantes = array_map(static fn (array $c): float => array_sum($c) / count($c), $clusters);

        $filas = [];

        foreach (array_values($filasProvisionales) as $indice => $celdas) {
            $salida = [];

            foreach ($celdas as $celda) {
                $columna = $this->indiceCluster($representantes, $celda['x_start'], $tolerancia);

                $salida[] = [
                    'column' => $columna + 1,
                    'text' => $celda['text'],
                    'confidence' => $celda['confidence'],
                    'bounding_box' => $celda['box'],
                ];
            }

            usort($salida, static fn (array $a, array $b): int => $a['column'] <=> $b['column']);

            $filas[] = [
                'row' => $indice + 1,
                'cells' => $salida,
            ];
        }

        return $filas;
    }

    /**
     * @param  list<float>  $representantes
     */
    private function indiceCluster(array $representantes, int $inicio, float $tolerancia): int
    {
        $mejor = 0;
        $mejorDistancia = null;

        foreach ($representantes as $indice => $representante) {
            $distancia = abs($inicio - $representante);

            if ($mejorDistancia === null || $distancia < $mejorDistancia) {
                $mejor = $indice;
                $mejorDistancia = $distancia;
            }
        }

        return $mejor;
    }

    /**
     * @param  list<float>  $valores
     */
    private function mediana(array $valores): float
    {
        $valores = array_values(array_filter($valores, static fn (float $v): bool => $v > 0));

        if ($valores === []) {
            return 1.0;
        }

        sort($valores);

        $n = count($valores);
        $mitad = intdiv($n, 2);

        return $n % 2 === 1
            ? $valores[$mitad]
            : ($valores[$mitad - 1] + $valores[$mitad]) / 2;
    }
}
