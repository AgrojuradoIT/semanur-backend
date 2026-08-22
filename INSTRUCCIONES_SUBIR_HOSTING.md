# Publicación en cPanel

Esta guía complementa `DEPLOY_README.md`; no autoriza mantenimiento por URL.

## Estructura correcta

```text
/home/USUARIO/apps/semanur-backend/     # aplicación privada
  app/
  bootstrap/
  config/
  routes/
  storage/
  vendor/
  public/

Dominio backsm.agrojurado.com
Document root: /home/USUARIO/apps/semanur-backend/public
```

No ubique la aplicación completa dentro de `public_html`. Si el proveedor no
permite seleccionar `public/` como raíz del dominio, el ambiente no cumple el
baseline y debe cambiarse la configuración o el proveedor; no se corrige con un
`.htaccess` que exponga la raíz del proyecto.

## Operaciones

Use exclusivamente Terminal de cPanel/SSH o un pipeline autenticado:

```bash
cd /home/USUARIO/apps/semanur-backend
php scripts/operations/clear_cache.php
php scripts/operations/link_storage.php
```

No visite URLs como `/deploy.php`, `/clear_cache.php`, `/link_storage.php` ni
diagnósticos. Esos nombres deben responder 403/404.

## Base de datos

- La base local no representa los registros de producción.
- No importe una copia local sobre producción.
- No ejecute `artisan migrate`.
- Aplique solamente el SQL productivo aprobado, con preflight/postflight y
  respaldo restaurable.

## Verificación

- `APP_DEBUG=false` y `APP_ENV=production`.
- HTTPS obligatorio.
- `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `LOG_LEVEL=warning` y
  `CORS_ALLOWED_ORIGINS` limitado al dominio web real.
- `/up` responde sin revelar configuración.
- Las rutas públicas operativas/diagnósticas responden 403/404.
- El endpoint de versión publica la metadata de integridad del APK activo.
- No hay índices de directorio ni acceso directo a evidencia/APKs.
