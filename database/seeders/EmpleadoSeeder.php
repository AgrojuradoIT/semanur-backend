<?php

namespace Database\Seeders;

use App\Models\Empleado;
use Illuminate\Database\Seeder;
use PhpOffice\PhpSpreadsheet\IOFactory;

class EmpleadoSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('../docs/EMPLEADOSS.xlsx');
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $rows = $sheet->toArray(null, true, true, true);
        if (count($rows) < 2) return;

        $headers = $rows[1];
        $colMap = [];
        foreach ($headers as $colKey => $val) {
            $cleanVal = strtoupper(trim(preg_replace('/\s+/', ' ', $val ?? '')));
            if (str_contains($cleanVal, 'NOMBRE')) $colMap['NOMBRE'] = $colKey;
            elseif (str_contains($cleanVal, 'CARGO')) $colMap['CARGO'] = $colKey;
        }

        $nombreKey = $colMap['NOMBRE'] ?? 'B';
        $cargoKey = $colMap['CARGO'] ?? 'C';

        $count = 0;
        $updated = 0;

        for ($i = 2; $i <= count($rows); $i++) {
            $row = $rows[$i];
            $nombreCompleto = trim($row[$nombreKey] ?? '');
            $cargoNombre = trim($row[$cargoKey] ?? '');

            if (empty($nombreCompleto)) {
                continue;
            }

            // Separar nombres y apellidos
            $parts = explode(' ', preg_replace('/\s+/', ' ', $nombreCompleto));
            if (count($parts) >= 3) {
                $nombres = trim($parts[0] . ' ' . $parts[1]);
                $apellidos = trim(implode(' ', array_slice($parts, 2)));
            } elseif (count($parts) === 2) {
                $nombres = $parts[0];
                $apellidos = $parts[1];
            } else {
                $nombres = $parts[0];
                $apellidos = '';
            }

            // Buscar existente por nombre completo (via accessor o parcial)
            $existing = Empleado::where('nombres', 'LIKE', $parts[0] . '%')
                ->where('apellidos', 'LIKE', '%' . (end($parts) ?? '') . '%')
                ->first();

            if ($existing) {
                $existing->update([
                    'cargo' => $cargoNombre ?: $existing->cargo,
                    'estado' => 'activo',
                ]);
                $updated++;
            } else {
                Empleado::create([
                    'nombres' => $nombres,
                    'apellidos' => $apellidos,
                    'cargo' => $cargoNombre,
                    'estado' => 'activo',
                ]);
                $count++;
            }
        }

        // Inyectar a Robinson Casierra como Jefe de Taller
        Empleado::updateOrCreate(
            ['id' => 51],
            [
                'nombres' => 'ROBINSON',
                'apellidos' => 'CASIERRA',
                'cargo' => 'Jefe de Taller',
                'estado' => 'activo'
            ]
        );
        $this->command->info("Robinson Casierra (Jefe de Taller) inyectado manualmente.");

        $this->command->info("Empleados procesados. Nuevos: {$count}, Actualizados: {$updated}");
    }
}
