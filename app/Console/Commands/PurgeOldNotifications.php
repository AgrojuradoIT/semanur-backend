<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Notificacion;
use Carbon\Carbon;

class PurgeOldNotifications extends Command
{
    protected $signature = 'app:purge-notifications
                            {--days=90 : Eliminar notificaciones leídas con más de N días}
                            {--dry-run : Solo muestra cuántas se eliminarían, sin borrar}';

    protected $description = 'Elimina notificaciones leídas antiguas para mantener la tabla limpia';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = Carbon::now()->subDays($days);
        $isDryRun = $this->option('dry-run');

        $query = Notificacion::whereNotNull('fecha_leido')
            ->where('created_at', '<', $cutoff);

        $count = $query->count();

        if ($count === 0) {
            $this->info("No hay notificaciones leídas con más de {$days} días. Nada que eliminar.");
            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->info("[DRY RUN] Se eliminarían {$count} notificaciones leídas anteriores a {$cutoff->format('d/m/Y')}.");
            return self::SUCCESS;
        }

        $query->delete();
        $this->info("Se eliminaron {$count} notificaciones leídas anteriores a {$cutoff->format('d/m/Y')}.");

        return self::SUCCESS;
    }
}
