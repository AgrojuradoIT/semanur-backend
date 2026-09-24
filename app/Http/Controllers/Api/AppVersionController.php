<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AppVersionController extends Controller
{
    /**
     * GET /api/app/version
     *
     * Retorna información sobre la última versión activa de la app.
     * Endpoint público — no requiere autenticación.
     *
     * Prioridad: DB (app_versions) → .env fallback
     */
    public function checkVersion(Request $request)
    {
        $active = AppVersion::active();

        if ($active) {
            if (! $active->hasCompleteIntegrityMetadata()) {
                return response()->json([
                    'message' => 'La versión activa no tiene metadata de integridad completa.',
                ], 503);
            }

            // Construir URL de descarga usando el endpoint seguro (evita errores 403 de Apache/cPanel)
            $downloadUrl = url('/api/app/download');

            return response()->json([
                'latest_version' => $active->version,
                'min_version' => $active->min_version,
                'download_url' => $downloadUrl,
                'release_notes' => $active->release_notes ?? '',
                'force_update' => $active->force_update,
                'apk_sha256' => strtolower($active->apk_sha256),
                'apk_size_bytes' => (int) $active->apk_size_bytes,
                'package_name' => $active->package_name,
                'signer_sha256' => strtoupper($active->signer_sha256),
            ]);
        }

        // Fallback al .env (retrocompatibilidad)
        return response()->json([
            'latest_version' => config('app_version.latest', '1.0.0'),
            'min_version' => config('app_version.min', '1.0.0'),
            'download_url' => config('app_version.download_url', ''),
            'release_notes' => config('app_version.release_notes', ''),
            'force_update' => config('app_version.force_update', false),
            'apk_sha256' => config('app_version.apk_sha256'),
            'apk_size_bytes' => config('app_version.apk_size_bytes'),
            'package_name' => config('app_version.package_name'),
            'signer_sha256' => config('app_version.signer_sha256'),
        ]);
    }

    /**
     * GET /api/app/download
     *
     * Descarga el APK activo directamente a través de PHP, evitando restricciones
     * de servidor web sobre archivos .apk en la carpeta storage.
     */
    public function download()
    {
        $active = AppVersion::active();

        if (! $active || empty($active->apk_path) || ! $active->hasCompleteIntegrityMetadata()) {
            return response()->json(['message' => 'No active version found'], 404);
        }

        if (! Storage::disk('public')->exists($active->apk_path)) {
            return response()->json(['message' => 'APK file not found on server'], 404);
        }

        $path = Storage::disk('public')->path($active->apk_path);

        if (filesize($path) !== (int) $active->apk_size_bytes) {
            return response()->json([
                'message' => 'El APK activo no superó la verificación de integridad.',
            ], 503);
        }

        $digest = base64_encode(hex2bin(strtolower($active->apk_sha256)));

        return response()->download($path, 'semanur-v'.$active->version.'.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Content-Length' => (string) $active->apk_size_bytes,
            'Digest' => 'sha-256='.$digest,
            'ETag' => '"'.strtolower($active->apk_sha256).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600, immutable',
        ]);
    }
}
