<?php

namespace App\Providers;

use App\Models\Empleado;
use App\Models\InventarioImportacion;
use App\Models\Notificacion;
use App\Models\OrdenTrabajo;
use App\Models\PrestamoHerramienta;
use App\Models\Producto;
use App\Models\RegistroCombustible;
use App\Models\TransaccionInventario;
use App\Models\Vehiculo;
use App\Observers\EmpleadoObserver;
use App\Observers\NotificacionObserver;
use App\Observers\TransaccionInventarioObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Relation::morphMap([
            'OrdenTrabajo' => OrdenTrabajo::class,
            'PrestamoHerramienta' => PrestamoHerramienta::class,
            'Vehiculo' => Vehiculo::class,
            'RegistroCombustible' => RegistroCombustible::class,
            'saldo_inicial' => Producto::class,
            'compra_import' => InventarioImportacion::class,
            'EmpleadoTexto' => Empleado::class,
            'Tercero' => RegistroCombustible::class,
        ]);

        TransaccionInventario::observe(TransaccionInventarioObserver::class);
        Notificacion::observe(NotificacionObserver::class);
        Empleado::observe(EmpleadoObserver::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));
            $accountKey = hash('sha256', $email);

            return [
                Limit::perMinute(5)->by("login:account:{$accountKey}"),
                Limit::perMinute(20)->by('login:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('app-version', function (Request $request) {
            return Limit::perMinute(60)->by('app-version:'.$request->ip());
        });

        RateLimiter::for('app-download', function (Request $request) {
            return Limit::perMinute(10)->by('app-download:'.$request->ip());
        });
    }
}
