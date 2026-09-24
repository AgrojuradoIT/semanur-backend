# Despliegue seguro del backend Semanur

## Reglas obligatorias

1. El `document root` del dominio debe apuntar exclusivamente a `backend/public`.
2. Nunca se copian al directorio público `.env`, `app`, `bootstrap`, `config`,
   `database`, `routes`, `storage`, `vendor`, `scripts`, pruebas, respaldos ni SQL.
3. No existen scripts de mantenimiento por HTTP. Las operaciones se realizan en
   una terminal autenticada de cPanel/SSH o mediante el pipeline.
4. No se ejecuta `php artisan migrate` en producción. Los cambios de base de
   datos se aplican mediante el paquete SQL revisado, después de un respaldo y
   de ejecutar su preflight.
5. Nunca se reemplaza la base de producción con una exportación local.

## Secuencia de liberación

1. Crear snapshot consistente de la base y respaldo verificable de archivos.
2. Ensayar restauración y SQL en un clon de producción.
3. Construir un artefacto desde un commit identificado; excluir `.env`, pruebas,
   logs, sesiones, archivos temporales y respaldos.
4. Instalar el artefacto fuera del webroot y conservar `public/` como única raíz
   pública.
5. Configurar secretos directamente en el servidor (`APP_ENV=production`,
   `APP_DEBUG=false`, HTTPS y credenciales propias del ambiente). Exigir además
   `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, un `APP_KEY` único,
   `LOG_LEVEL=warning` y `CORS_ALLOWED_ORIGINS` con los orígenes web exactos.
6. Ejecutar desde terminal autenticada:

   ```bash
   php scripts/operations/deploy.php
   ```

   Este comando sólo prepara cachés/assets y **no modifica la base de datos**.
7. Ejecutar el preflight SQL, aplicar el SQL aprobado y ejecutar el postflight.
8. Realizar smoke tests autenticados en staging antes de cambiar tráfico.

## Controles del artefacto

- `public/` sólo contiene `index.php`, `.htaccess`, `robots.txt`, favicon y assets
  generados explícitamente aprobados.
- Ningún `deploy.php`, `clear_cache.php`, `link_storage.php`, diagnóstico,
  respaldo o archivo `.zip` puede aparecer bajo el webroot.
- El servidor debe negar cualquier PHP distinto de `index.php` y el acceso
  directo a `storage/apks` y `storage/media`; también debe negar archivos de
  respaldo/artefactos (`zip`, `tar`, `sql`, `bak`, logs). En Nginx/IIS se deben
  reproducir explícitamente estas reglas porque `.htaccess` sólo aplica a Apache.
- Las versiones APK activas requieren SHA-256, tamaño, package name y huella del
  firmante antes de ser publicadas.
- `php artisan db:seed` está prohibido en producción y los seeders fallan cerrados
  fuera de `local/testing`.

## Rollback

Ante cualquier discrepancia, detener el cambio, conservar evidencia y restaurar
el snapshot completo. No ejecutar migraciones, rollback de Laravel ni SQL
correctivo improvisado sobre producción.
