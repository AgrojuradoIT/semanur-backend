CREATE TABLE `programacion` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `empleado_id` bigint(20) unsigned NOT NULL,
  `vehiculo_id` bigint(20) unsigned DEFAULT NULL,
  `labor` varchar(255) NOT NULL,
  `ubicacion` varchar(255) DEFAULT NULL,
  `estado` enum('pendiente','en_progreso','pausado','completado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `orden_trabajo_id` bigint(20) unsigned DEFAULT NULL,
  `es_novedad` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `programacion_empleado_id_foreign` (`empleado_id`),
  KEY `programacion_vehiculo_id_foreign` (`vehiculo_id`),
  KEY `programacion_orden_trabajo_id_foreign` (`orden_trabajo_id`),
  CONSTRAINT `programacion_empleado_id_foreign` FOREIGN KEY (`empleado_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `programacion_orden_trabajo_id_foreign` FOREIGN KEY (`orden_trabajo_id`) REFERENCES `ordenes_trabajo` (`orden_trabajo_id`) ON DELETE SET NULL,
  CONSTRAINT `programacion_vehiculo_id_foreign` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`vehiculo_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Opcional: Registrar la migración para evitar que se ejecute de nuevo si arreglan el servidor
INSERT INTO `migrations` (`migration`, `batch`) 
SELECT '2026_02_06_235000_create_programacion_table', MAX(batch) + 1 FROM `migrations`;
