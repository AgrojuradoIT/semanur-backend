# Guía de Despliegue en Hosting Compartido (Sin Consola/SSH)

Esta guía te ayudará a subir tu backend Laravel a un hosting tipo cPanel/Plesk donde no puedes ejecutar comandos.

## 1. Preparación de Base de Datos
Como no tienes consola en el hosting, no podemos correr migraciones allá.
1.  Abre tu gestor de base de datos local (HeidiSQL, phpMyAdmin, Workbench).
2.  Selecciona tu base de datos local.
3.  Haz una **Exportación completa** (Estructura y Datos) a un archivo `.sql` (ej: `semanur_backup.sql`).

## 2. Preparación de Archivos
Asegúrate de tener todo listo para subir.
1.  Ve a la carpeta del proyecto `backend`.
2.  Debes subir **TODOS** los archivos y carpetas, **EXCEPTO**:
    *   `.git`
    *   `node_modules`
    *   `tests`
    *   `deploy_readme.md` (este archivo)
3.  **IMPORTANTE**: Asegúrate de subir la carpeta `vendor`. (Si usas FileZilla, tardará un rato porque son muchos archivos pequeños).

## 3. Subida al Servidor
1.  Sube los archivos a la carpeta pública de tu hosting (usualmente `public_html` o `www`).
    *   *Nota: He creado un archivo `.htaccess` en la raíz para que redirija todo automáticamente a la carpeta `public` que trae Laravel.*

## 4. Configuración del Entorno (.env)
1.  En tu hosting, busca el archivo `.env`. Si no está, sube tu `.env` local y renómbralo (o edítalo).
2.  Cambia las siguientes líneas para producción:
    ```ini
    APP_ENV=production
    APP_DEBUG=false
    APP_URL=https://backsm.agrojurado.com

    DB_connection=mysql
    DB_HOST=127.0.0.1  <-- Generalmente es localhost, verifica con tu hosting
    DB_PORT=3306
    DB_DATABASE=nombre_base_datos_hosting
    DB_USERNAME=usuario_base_datos_hosting
    DB_PASSWORD=contraseña_base_datos_hosting
    ```

## 5. Importar Base de Datos
1.  Entra al **phpMyAdmin** de tu hosting.
2.  Selecciona la base de datos que creaste.
3.  Ve a la pestaña **Importar**.
4.  Sube el archivo `semanur_backup.sql` que generaste en el paso 1.

## 6. Scripts de Ayuda (Post-Install)
Como no hay consola, he creado dos scripts PHP que puedes ejecutar desde el navegador para realizar tareas de mantenimiento.

### A. Enlazar Storage (Imágenes)
Para que las imágenes públicas se vean:
1.  Visita: `https://backsm.agrojurado.com/link_storage.php`
2.  Debería decir "Enlace simbólico creado correctamente".
3.  Por seguridad, **borra el archivo `link_storage.php`** después de usarlo.

### B. Limpiar Caché
Si haces cambios en el `.env` o subes código nuevo y no lo ves reflejado:
1.  Visita: `https://backsm.agrojurado.com/clear_cache.php`
2.  Esto borrará la caché de configuración, rutas y vistas.

---
## Solución de Problemas Comunes

*   **Error 500**: Generalmente es un error en el archivo `.env` (credenciales mal puestas) o permisos de carpetas.
*   **Permisos**: Asegúrate de que las carpetas `storage` y `bootstrap/cache` tengan permisos de escritura (755 o 775).
*   **Imágenes rotas**: Ejecuta el script `link_storage.php`.
