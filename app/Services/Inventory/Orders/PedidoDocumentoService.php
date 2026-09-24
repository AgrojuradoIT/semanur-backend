<?php

namespace App\Services\Inventory\Orders;

use App\Models\PedidoDocumento;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\PedidoInventarioLinea;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Pipeline de documentos privados del pedido (U3).
 *
 * Contrato de seguridad:
 * - Toda validación corre ANTES de escribir en disco. Un rechazo nunca deja
 *   archivos.
 * - El original se conserva intacto como evidencia; el derivado (preview.jpg)
 *   es una re-codificación JPEG sin metadatos y con orientación aplicada.
 *   Excepción BE-1: la factura PDF no genera derivado (sin renderer en el
 *   proyecto) y el recurso expone preview_disponible=false.
 * - El disco debe ser privado ('local'); nunca public/.
 * - Cualquier fallo posterior a la escritura elimina original y derivado
 *   (misma compensación que MediaService).
 */
class PedidoDocumentoService
{
    public const ROL_PANTALLAZO_SIIGO = 'pantallazo_siigo';

    public const ROL_FACTURA = 'factura';

    /** @var list<string> */
    public const ROLES = [self::ROL_PANTALLAZO_SIIGO, self::ROL_FACTURA];

    private const EXTENSIONES_PERMITIDAS = ['jpg', 'jpeg', 'png'];

    private const MIMES_PERMITIDOS = ['image/jpeg', 'image/png'];

    /**
     * La factura acepta foto o PDF real (BE-1). El pantallazo Siigo sigue
     * image-only: alimenta el derivado image/jpeg que consume Gemini.
     */
    private const EXTENSIONES_FACTURA = ['jpg', 'jpeg', 'png', 'pdf'];

    private const MIMES_FACTURA = ['image/jpeg', 'image/png', 'application/pdf'];

    public const MIME_PDF = 'application/pdf';

    /** @var list<string> */
    private const FIRMAS_DE_SCRIPT = ['<?php', '<script', '<svg', '<?xml'];

    /**
     * Valida, almacena y registra un documento privado del pedido.
     *
     * @throws ValidationException rechazo de contenido o límites (HTTP 422)
     * @throws RuntimeException fallo técnico de almacenamiento o derivado (HTTP 500)
     */
    public function store(
        PedidoInventario $pedido,
        UploadedFile $file,
        string $rol,
        ?int $replacesDocumentoId = null,
        ?int $pedidoLineaId = null,
        ?User $actor = null,
    ): PedidoDocumento {
        $this->assertRolValido($rol);
        $this->validarReferencias($pedido, $replacesDocumentoId, $pedidoLineaId);

        $inspeccion = $this->inspeccionarArchivo($file, $rol);

        $this->validarLimites($pedido, $inspeccion['size_bytes']);

        $disk = $this->diskName();
        $folder = (string) Str::uuid();
        $storageKey = sprintf('pedidos/%s/%s/original.%s', $pedido->uuid, $folder, $inspeccion['extension']);
        $previewKey = self::derivativePathFor($storageKey);

        // El PDF no tiene derivado: GD no rasteriza PDFs y el proyecto no
        // trae renderer (sin imagick, sin spatie/pdf-to-image, sin
        // pdftoppm). Se conserva el original y el recurso expone
        // preview_disponible=false. El path de imágenes queda intacto.
        $esPdf = $inspeccion['mime'] === self::MIME_PDF;

        $previewBytes = $esPdf
            ? null
            : $this->buildPreview(
                $inspeccion['resource'],
                $this->orientation($inspeccion['real_path'], $inspeccion['bytes']),
            );

        $datos = [
            'rol' => $rol,
            'storage_key' => $storageKey,
            'mime_type' => $inspeccion['mime'],
            'size_bytes' => $inspeccion['size_bytes'],
            'width' => $inspeccion['width'],
            'height' => $inspeccion['height'],
            'sha256' => $inspeccion['sha256'],
            'replaces_documento_id' => $replacesDocumentoId,
            'pedido_linea_id' => $pedidoLineaId,
        ];

        $escritos = [];

        try {
            if (! Storage::disk($disk)->put($storageKey, $inspeccion['bytes'])) {
                throw new RuntimeException('No se pudo almacenar el documento privado.');
            }

            $escritos[] = $storageKey;

            if ($previewBytes !== null) {
                if (! Storage::disk($disk)->put($previewKey, $previewBytes)) {
                    throw new RuntimeException('No se pudo almacenar el derivado del documento privado.');
                }

                $escritos[] = $previewKey;
            }

            return $this->persistir($pedido, $actor ?? $this->actorDesdeContexto(), $datos);
        } catch (Throwable $exception) {
            // El filesystem no es transaccional: compensar cualquier fallo
            // posterior a la escritura para no dejar objetos sin referencia.
            $this->eliminarArchivos($disk, $escritos);

            if (! $exception instanceof ValidationException) {
                Log::warning('pedido_documento.store_failed', [
                    'pedido_uuid' => $pedido->uuid,
                    'rol' => $rol,
                    'error' => $exception->getMessage(),
                ]);
            }

            throw $exception;
        }
    }

    /**
     * Ruta del derivado a partir de la clave del original. Convención única
     * compartida con el reaper de huérfanos.
     */
    public static function derivativePathFor(string $storageKey): string
    {
        return dirname($storageKey).'/preview.jpg';
    }

    public function previewPath(PedidoDocumento $documento): string
    {
        return self::derivativePathFor((string) $documento->storage_key);
    }

    /**
     * Inserta documento + evento de auditoría en UNA transacción, bajo lock de
     * la cabecera del pedido para serializar revisión y límites por pedido.
     *
     * @param  array<string, mixed>  $datos
     */
    protected function persistir(PedidoInventario $pedido, User $actor, array $datos): PedidoDocumento
    {
        return DB::transaction(function () use ($pedido, $actor, $datos): PedidoDocumento {
            $bloqueado = PedidoInventario::query()
                ->whereKey($pedido->pedido_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $bloqueado->estaEnBorrador()) {
                $this->fallar('documento', 'El pedido ya no está en borrador y no admite nuevos documentos.');
            }

            // Revalidar los cupos bajo lock: dos cargas concurrentes pudieron
            // pasar el chequeo previo antes de que cualquiera persistiera.
            $this->validarLimites($bloqueado, (int) $datos['size_bytes']);

            $revision = ((int) PedidoDocumento::query()
                ->where('pedido_id', $bloqueado->pedido_id)
                ->where('rol', $datos['rol'])
                ->max('documento_revision')) + 1;

            $documento = PedidoDocumento::create([
                'pedido_id' => $bloqueado->pedido_id,
                'rol' => $datos['rol'],
                'documento_revision' => $revision,
                'storage_key' => $datos['storage_key'],
                'mime_type' => $datos['mime_type'],
                'size_bytes' => $datos['size_bytes'],
                'width' => $datos['width'],
                'height' => $datos['height'],
                'sha256' => $datos['sha256'],
                'created_by' => $actor->id,
                'replaced_document_id' => $datos['replaces_documento_id'],
                'pedido_linea_id' => $datos['pedido_linea_id'],
            ]);

            PedidoEvento::create([
                'pedido_id' => $bloqueado->pedido_id,
                'pedido_linea_id' => $datos['pedido_linea_id'],
                'event_type' => 'documento_cargado',
                'actor_user_id' => $actor->id,
                'actor_rol_snapshot' => (string) $actor->role,
                'datos_antes' => null,
                'datos_despues' => [
                    'documento_id' => $documento->documento_id,
                    'rol' => $documento->rol,
                    'documento_revision' => $documento->documento_revision,
                    'mime_type' => $documento->mime_type,
                    'size_bytes' => $documento->size_bytes,
                    'width' => $documento->width,
                    'height' => $documento->height,
                    'sha256' => $documento->sha256,
                    'replaces_documento_id' => $documento->replaced_documento_id,
                    'pedido_linea_id' => $documento->pedido_linea_id,
                ],
                'revision' => $bloqueado->versionActual(),
            ]);

            return $documento;
        });
    }

    /**
     * @return array{extension: string, mime: string, size_bytes: int, width: int|null, height: int|null, sha256: string, bytes: string, real_path: string, resource: GdImage|null}
     */
    private function inspeccionarArchivo(UploadedFile $file, string $rol): array
    {
        if (! $file->isValid()) {
            $this->fallar('file', 'La carga del archivo falló o llegó incompleta.');
        }

        $size = $file->getSize();

        if (! is_int($size) || $size <= 0) {
            $this->fallar('file', 'No se pudo determinar el tamaño del archivo.');
        }

        $maxFileBytes = $this->megabytesEnBytes((float) config('inventory_orders.documents.max_file_mb', 10));

        if ($maxFileBytes > 0 && $size > $maxFileBytes) {
            $this->fallar('file', sprintf('El archivo supera el máximo de %s MB.', (string) config('inventory_orders.documents.max_file_mb', 10)));
        }

        // Allowlist por rol: factura acepta PDF real; pantallazo sigue
        // image-only porque su derivado image/jpeg alimenta a Gemini.
        $esFactura = $rol === self::ROL_FACTURA;
        $extensiones = $esFactura ? self::EXTENSIONES_FACTURA : self::EXTENSIONES_PERMITIDAS;
        $mimes = $esFactura ? self::MIMES_FACTURA : self::MIMES_PERMITIDOS;

        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, $extensiones, true)) {
            $this->fallar('file', $esFactura
                ? 'La extensión debe ser JPG, JPEG, PNG o PDF.'
                : 'La extensión debe ser JPG, JPEG o PNG.');
        }

        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_file($realPath)) {
            $this->fallar('file', 'No se pudo acceder al archivo temporal de la carga.');
        }

        // MIME real vía finfo (no confiar en el nombre ni en el Content-Type).
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);
        $mime = is_string($mime) ? strtolower(trim($mime)) : '';

        if (! in_array($mime, $mimes, true)) {
            $this->fallar('file', $esFactura
                ? 'El contenido real del archivo no es una imagen JPEG/PNG ni un PDF.'
                : 'El contenido real del archivo no es una imagen JPEG o PNG.');
        }

        $mimeEsperado = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => self::MIME_PDF,
            default => null,
        };

        if ($mimeEsperado === null || $mime !== $mimeEsperado) {
            $this->fallar('file', 'La extensión y el contenido real del archivo no coinciden.');
        }

        $bytes = file_get_contents($realPath);

        if (! is_string($bytes) || $bytes === '') {
            $this->fallar('file', 'No se pudo leer el contenido del archivo.');
        }

        if ($this->tieneFirmaDeScript($bytes)) {
            $this->fallar('file', 'El archivo contiene firmas de script o marcado no permitidas.');
        }

        if ($mime === self::MIME_PDF) {
            // width/height quedan null (columnas nullable, sin migración):
            // un PDF no tiene dimensiones de imagen y GD no lo decodifica.
            if (! $this->pdfEstructuralmenteCompleto($bytes)) {
                $this->fallar('file', 'El PDF está truncado: no contiene la cabecera %PDF ni el cierre %%EOF.');
            }

            return [
                'extension' => $extension,
                'mime' => $mime,
                'size_bytes' => $size,
                'width' => null,
                'height' => null,
                'sha256' => hash('sha256', $bytes),
                'bytes' => $bytes,
                'real_path' => $realPath,
                'resource' => null,
            ];
        }

        if (! $this->archivoCompleto($bytes, $mime)) {
            $this->fallar('file', 'La imagen está truncada: no contiene el cierre estructural del formato.');
        }

        $dimensions = @getimagesize($realPath);

        if ($dimensions === false || (int) $dimensions[0] < 1 || (int) $dimensions[1] < 1) {
            $this->fallar('file', 'No se pudieron leer las dimensiones reales de la imagen.');
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        $maxPixels = (int) round(((float) config('inventory_orders.documents.max_megapixels', 25)) * 1_000_000);

        if ($maxPixels > 0 && ($width * $height) > $maxPixels) {
            $this->fallar('file', sprintf('La imagen supera el máximo de %s megapíxeles.', (string) config('inventory_orders.documents.max_megapixels', 25)));
        }

        // Decodificación GD completa: rechaza archivos truncados o corruptos
        // que solo pasarían una lectura de cabecera.
        $resource = @imagecreatefromstring($bytes);

        if (! $resource instanceof GdImage) {
            $this->fallar('file', 'La imagen está truncada o no se puede decodificar por completo.');
        }

        return [
            'extension' => $extension,
            'mime' => $mime,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'sha256' => hash('sha256', $bytes),
            'bytes' => $bytes,
            'real_path' => $realPath,
            'resource' => $resource,
        ];
    }

    private function validarLimites(PedidoInventario $pedido, int $sizeBytes): void
    {
        $maxPorPedido = (int) config('inventory_orders.documents.max_per_order', 20);

        if ($maxPorPedido > 0) {
            $cantidad = PedidoDocumento::query()->where('pedido_id', $pedido->pedido_id)->count();

            if ($cantidad >= $maxPorPedido) {
                $this->fallar('file', sprintf('El pedido alcanzó el máximo de %d documentos.', $maxPorPedido));
            }
        }

        $maxTotalBytes = $this->megabytesEnBytes((float) config('inventory_orders.documents.max_total_mb', 100));

        if ($maxTotalBytes > 0) {
            $acumulado = (int) PedidoDocumento::query()->where('pedido_id', $pedido->pedido_id)->sum('size_bytes');

            if ($acumulado + $sizeBytes > $maxTotalBytes) {
                $this->fallar('file', sprintf('La suma de documentos del pedido supera el máximo de %s MB.', (string) config('inventory_orders.documents.max_total_mb', 100)));
            }
        }
    }

    private function validarReferencias(PedidoInventario $pedido, ?int $replacesDocumentoId, ?int $pedidoLineaId): void
    {
        if ($replacesDocumentoId !== null && ! PedidoDocumento::query()
            ->where('documento_id', $replacesDocumentoId)
            ->where('pedido_id', $pedido->pedido_id)
            ->exists()) {
            $this->fallar('replaces_documento_id', 'El documento a sustituir no pertenece a este pedido.');
        }

        if ($pedidoLineaId !== null && ! PedidoInventarioLinea::query()
            ->where('linea_id', $pedidoLineaId)
            ->where('pedido_id', $pedido->pedido_id)
            ->exists()) {
            $this->fallar('pedido_linea_id', 'La línea indicada no pertenece a este pedido.');
        }
    }

    private function assertRolValido(string $rol): void
    {
        if (! in_array($rol, self::ROLES, true)) {
            $this->fallar('rol', 'El rol debe ser pantallazo_siigo o factura.');
        }
    }

    /**
     * GD/libjpeg puede reconstruir JPEG levemente truncados rellenando con gris,
     * así que la decodificación por sí sola no garantiza integridad. Se exige
     * además el cierre estructural del formato (EOI en JPEG, IEND en PNG).
     */
    private function archivoCompleto(string $bytes, string $mime): bool
    {
        $cola = substr($bytes, -64);

        return match ($mime) {
            'image/jpeg' => str_contains($cola, "\xFF\xD9"),
            'image/png' => str_contains($cola, 'IEND'),
            default => false,
        };
    }

    /**
     * Chequeo estructural mínimo del PDF: cabecera %PDF al inicio (finfo ya
     * confirmó application/pdf) y marcador %%EOF en la cola. No confía en la
     * extensión ni en el Content-Type del cliente.
     */
    private function pdfEstructuralmenteCompleto(string $bytes): bool
    {
        $inicio = ltrim($bytes, "\xEF\xBB\xBF\x00\x09\x0A\x0D\x20");

        if (! str_starts_with($inicio, '%PDF-')) {
            return false;
        }

        return str_contains(substr($bytes, -2048), '%%EOF');
    }

    private function tieneFirmaDeScript(string $bytes): bool
    {
        // BOM UTF-8, NUL y espacios iniciales se ignoran para detectar firmas
        // disfrazadas con prefijos inocuos.
        $inicio = ltrim(substr($bytes, 0, 1024), "\xEF\xBB\xBF\x00\x09\x0A\x0D\x20");
        $inicio = strtolower($inicio);

        foreach (self::FIRMAS_DE_SCRIPT as $firma) {
            if (str_starts_with($inicio, $firma)) {
                return true;
            }
        }

        return false;
    }

    private function orientation(string $realPath, string $bytes): int
    {
        if (function_exists('exif_read_data')) {
            try {
                $data = @exif_read_data($realPath);
                $value = is_array($data) ? (int) ($data['Orientation'] ?? 1) : 1;

                return $value >= 1 && $value <= 8 ? $value : 1;
            } catch (Throwable) {
                return JpegExifOrientation::DEFAULT_ORIENTATION;
            }
        }

        return JpegExifOrientation::read($bytes);
    }

    /**
     * Re-codifica a JPEG sobre fondo blanco (aplana alfa) aplicando la
     * orientación EXIF. No escala: conserva las dimensiones originales.
     */
    private function buildPreview(GdImage $source, int $orientation): string
    {
        $oriented = $this->applyOrientation($source, $orientation);
        $width = imagesx($oriented);
        $height = imagesy($oriented);

        $canvas = imagecreatetruecolor($width, $height);

        if (! $canvas instanceof GdImage) {
            throw new RuntimeException('No se pudo preparar el derivado de la imagen.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);
        imagecopy($canvas, $oriented, 0, 0, 0, 0, $width, $height);

        $quality = (int) config('inventory_orders.documents.preview_jpeg_quality', 85);
        $quality = max(1, min(100, $quality));

        ob_start();
        $encoded = imagejpeg($canvas, null, $quality);
        $bytes = (string) ob_get_clean();

        if ($encoded === false || $bytes === '') {
            throw new RuntimeException('No se pudo generar el derivado JPEG del documento.');
        }

        return $bytes;
    }

    /**
     * Orientaciones EXIF 1-8. Las de espejo se resuelven con imageflip y las
     * de rotación con imagerotate (positivo = antihorario). Cualquier fallo de
     * GD lanza RuntimeException para que el llamador compense los archivos.
     */
    private function applyOrientation(GdImage $source, int $orientation): GdImage
    {
        return match ($orientation) {
            2 => $this->flip($source, IMG_FLIP_HORIZONTAL),
            3 => $this->rotate($source, 180),
            4 => $this->flip($source, IMG_FLIP_VERTICAL),
            5 => $this->rotate($this->flip($source, IMG_FLIP_HORIZONTAL), 90),
            6 => $this->rotate($source, -90),
            7 => $this->rotate($this->flip($source, IMG_FLIP_HORIZONTAL), -90),
            8 => $this->rotate($source, 90),
            default => $source,
        };
    }

    private function flip(GdImage $image, int $mode): GdImage
    {
        if (! imageflip($image, $mode)) {
            throw new RuntimeException('No se pudo orientar la imagen del documento.');
        }

        return $image;
    }

    private function rotate(GdImage $image, float $angle): GdImage
    {
        $white = imagecolorallocate($image, 255, 255, 255);
        $rotated = imagerotate($image, $angle, $white);

        if (! $rotated instanceof GdImage) {
            throw new RuntimeException('No se pudo rotar la imagen del documento.');
        }

        return $rotated;
    }

    public function diskName(): string
    {
        $disk = (string) config('inventory_orders.documents.disk', 'local');

        // El disco público está prohibido por diseño: la evidencia solo se
        // sirve a través del endpoint autorizado.
        if ($disk === '' || $disk === 'public') {
            throw new RuntimeException('El disco de documentos debe ser privado; public/ no está permitido.');
        }

        return $disk;
    }

    private function megabytesEnBytes(float $megabytes): int
    {
        return (int) floor($megabytes * 1024 * 1024);
    }

    /**
     * @param  list<string>  $paths
     */
    private function eliminarArchivos(string $disk, array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable) {
                // La compensación no debe enmascarar el error original.
            }
        }
    }

    private function actorDesdeContexto(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new RuntimeException('No hay actor autenticado para auditar el documento.');
        }

        return $actor;
    }

    private function fallar(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
