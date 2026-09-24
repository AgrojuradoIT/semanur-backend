<?php

namespace App\Services\Inventory\Orders\Ocr;

/**
 * Seam de proveedores OCR (plan sección 8).
 *
 * El proveedor entrega una estructura CRUDA NEUTRAL, independiente del
 * proveedor, que SiigoScreenshotMapper convierte al contrato estable de la
 * sección 4. El proveedor NO toca base de datos, archivos ni ledger.
 *
 * Forma cruda esperada (todas las claves salvo `pages` son opcionales):
 *
 * @return array{
 *     provider?: string,
 *     provider_version?: string|null,
 *     full_text?: string,
 *     pages: list<array{
 *         page_number: int,
 *         width?: int|null,
 *         height?: int|null,
 *         rows: list<array{
 *             row: int,
 *             cells: list<array{
 *                 column: int,
 *                 text: string,
 *                 confidence?: float|null,
 *                 bounding_box?: array{x: int, y: int, width: int, height: int}|null
 *             }>
 *         }>
 *     }>
 * }
 *
 * Reglas del seam:
 * - `confidence` ausente = null, nunca 1.0.
 * - `text` conserva el texto tal como lo leyó el proveedor; no se autocorrigen
 *   O/0 ni I/1.
 * - La geometría es relativa al documento/celda; el mapper reconstruye filas
 *   por cabeceras y celdas, no por píxeles fijos.
 */
interface PedidoOcrProvider
{
    /**
     * Extrae la estructura cruda de una imagen local.
     *
     * @param  string  $rutaAbsoluta  Ruta absoluta en disco privado.
     * @param  string  $mimeType  MIME real de la imagen.
     * @param  array<string, mixed>  $contexto  Metadatos del intento (documento_id, documento_revision, pedido_id, intento_id, fixture...).
     * @return array<string, mixed>
     *
     * @throws OcrException error clasificado (reintentable o terminal).
     */
    public function extraer(string $rutaAbsoluta, string $mimeType, array $contexto = []): array;

    public function nombre(): string;

    public function version(): ?string;
}
