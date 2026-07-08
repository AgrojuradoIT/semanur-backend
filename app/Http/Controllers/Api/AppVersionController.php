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
            // Construir URL de descarga usando el endpoint seguro (evita errores 403 de Apache/cPanel)
            $downloadUrl = url('/api/app/download');

            return response()->json([
                'latest_version' => $active->version,
                'min_version'    => $active->min_version,
                'download_url'   => $downloadUrl,
                'release_notes'  => $active->release_notes ?? '',
                'force_update'   => $active->force_update,
            ]);
        }

        // Fallback al .env (retrocompatibilidad)
        return response()->json([
            'latest_version' => config('app_version.latest', '1.0.0'),
            'min_version'    => config('app_version.min', '1.0.0'),
            'download_url'   => config('app_version.download_url', ''),
            'release_notes'  => config('app_version.release_notes', ''),
            'force_update'   => config('app_version.force_update', false),
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

        if (!$active || empty($active->apk_path)) {
            return response()->json(['message' => 'No active version found'], 404);
        }

        if (!Storage::disk('public')->exists($active->apk_path)) {
            return response()->json(['message' => 'APK file not found on server'], 404);
        }

        $path = Storage::disk('public')->path($active->apk_path);

        return response()->download($path, 'semanur-v' . $active->version . '.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
}
