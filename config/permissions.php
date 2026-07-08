<?php

/**
 * Permisos por defecto según el rol del usuario.
 * 
 * Los administradores (admin) siempre tienen acceso total a todos los módulos.
 * Para el resto de roles, se definen los módulos permitidos por defecto.
 * 
 * Un usuario puede tener permisos adicionales específicos en la columna `permisos`
 * de la tabla `users`, que se fusionan con los de su rol.
 */

return [

    'modulos' => [
        'combustible'  => 'Combustible',
        'checklists'   => 'Checklists',
        'inventario'   => 'Inventario',
        'taller'       => 'Taller (OT)',
        'flota'        => 'Flota (Vehículos)',
        'personal'     => 'Personal',
        'prestamos'    => 'Préstamos',
        'movimientos'  => 'Movimientos Inventario',
        'usuarios'     => 'Usuarios',
        'media'        => 'Archivos',
        'analitica'    => 'Analítica',
        'notificaciones' => 'Notificaciones',
    ],

    'roles' => [
        'admin' => [
            // Admin tiene acceso total automáticamente — no necesita lista
            'descripcion' => 'Acceso total al sistema',
        ],

        'jefe_taller' => [
            'descripcion' => 'Gestión del taller y personal',
            'default' => [
                'taller',
                'flota',
                'personal',
                'inventario',
                'prestamos',
                'checklists',
                'combustible',
                'analitica',
            ],
        ],

        'auxiliar_bodega' => [
            'descripcion' => 'Gestión de inventario y combustible',
            'default' => [
                'inventario',
                'movimientos',
                'prestamos',
                'combustible',
                'checklists',
            ],
        ],

        'operativo' => [
            'descripcion' => 'Operaciones básicas de campo',
            'default' => [
                'checklists',
                'combustible',
            ],
        ],

        'visualizador' => [
            'descripcion' => 'Solo consulta',
            'default' => [
                'analitica',
            ],
        ],
    ],

];
