<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Notificaciones Programadas — 7:00 AM y 2:00 PM (Colombia)
|--------------------------------------------------------------------------
| Revisa stock bajo, vencimientos de documentos y mantenimientos próximos.
| Genera notificaciones en MySQL con deduplicación de 24 horas.
*/
Schedule::command('app:check-notifications')
    ->twiceDaily(7, 14)
    ->timezone('America/Bogota')
    ->withoutOverlapping();

// Limpieza semanal: elimina notificaciones leídas con más de 90 días
Schedule::command('app:purge-notifications')
    ->weeklyOn(0, '3:00') // Domingo 3am
    ->timezone('America/Bogota')
    ->withoutOverlapping();

Artisan::command('app:sync-docs', function () {
    $this->info('Iniciando sincronizacion de vencimientos de documentos en vehiculos...');
    \App\Models\Vehiculo::all()->each(function($v) {
        $ultimoSoat = \App\Models\VehiculoDocumento::where('vehiculo_id', $v->vehiculo_id)
            ->where('tipo', 'soat')
            ->orderBy('fecha_vencimiento', 'desc')
            ->first();
        
        $v->fecha_vencimiento_soat = $ultimoSoat ? $ultimoSoat->fecha_vencimiento : null;

        $ultimoRtm = \App\Models\VehiculoDocumento::where('vehiculo_id', $v->vehiculo_id)
            ->where('tipo', 'tecnomecanica')
            ->orderBy('fecha_vencimiento', 'desc')
            ->first();
            
        $v->fecha_vencimiento_tecnomecanica = $ultimoRtm ? $ultimoRtm->fecha_vencimiento : null;

        $v->save();
        $this->line("Vehiculo [{$v->placa}]: SOAT -> " . ($v->fecha_vencimiento_soat ? $v->fecha_vencimiento_soat->format('Y-m-d') : 'N/D') . " | RTM -> " . ($v->fecha_vencimiento_tecnomecanica ? $v->fecha_vencimiento_tecnomecanica->format('Y-m-d') : 'N/D'));
    });
    $this->info('¡Sincronizacion completada con exito!');
})->purpose('Sincronizar vencimientos de SOAT y Tecnico-Mecanica en la tabla de vehiculos');

