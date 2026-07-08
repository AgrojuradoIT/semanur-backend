<?php

// Script para crear enlace simbólico en hosting compartido (sin SSH)
// Sube este archivo a la carpeta 'public' o 'public_html' y ejecútalo una vez desde el navegador:
// http://backsm.agrojurado.com/link_storage.php

$target = __DIR__ . '/../storage/app/public';
$shortcut = __DIR__ . '/storage';

if (file_exists($shortcut)) {
    echo "El enlace simbólico 'storage' ya existe.";
} else {
    if (symlink($target, $shortcut)) {
        echo "Enlace simbólico creado correctamente: $shortcut -> $target";
    } else {
        echo "Error: No se pudo crear el enlace simbólico. Verifica permisos o soporte de symlink.";
    }
}
