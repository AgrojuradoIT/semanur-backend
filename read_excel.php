<?php

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function listAllEmployees($filePath) {
    if (!file_exists($filePath)) {
        return;
    }
    $spreadsheet = IOFactory::load($filePath);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);

    echo "Listado Completo de Empleados:\n";
    for ($i = 2; $i <= count($rows); $i++) {
        $row = $rows[$i];
        if (empty($row['B']) && empty($row['C'])) continue;
        echo "ID: " . ($row['A'] ?? 'N/A') . " | Nombre: " . ($row['B'] ?? 'N/A') . " | Cargo: " . ($row['C'] ?? 'N/A') . "\n";
    }
}

listAllEmployees('d:/DEV/semanur_app/docs/EMPLEADOSS.xlsx');
