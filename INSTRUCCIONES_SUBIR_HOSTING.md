# 📤 Archivos para Subir al Hosting (cPanel)

## Timezone Fix - Backend

### Archivos Modificados/Creados

| # | Archivo Local | Ruta en Hosting | Prioridad |
|---|--------------|-----------------|-----------|
| 1 | `config/timezone.php` | `public_html/backsm.agrojurado.com/config/timezone.php` | 🔴 CRÍTICO |
| 2 | `bootstrap/app.php` | `public_html/backsm.agrojurado.com/bootstrap/app.php` | 🔴 CRÍTICO |
| 3 | `routes/api.php` | `public_html/backsm.agrojurado.com/routes/api.php` | 🔴 CRÍTICO |
| 4 | `config/database.php` | `public_html/backsm.agrojurado.com/config/database.php` | 🟡 IMPORTANTE |
| 5 | `app/Models/OrdenTrabajo.php` | `public_html/backsm.agrojurado.com/app/Models/OrdenTrabajo.php` | 🟡 IMPORTANTE |
| 6 | `app/Models/WorkSession.php` | `public_html/backsm.agrojurado.com/app/Models/WorkSession.php` | 🟡 IMPORTANTE |
| 7 | `app/Http/Controllers/Api/OrdenTrabajoApiController.php` | `public_html/backsm.agrojurado.com/app/Http/Controllers/Api/OrdenTrabajoApiController.php` | 🟡 IMPORTANTE |
| 8 | `app/Http/Controllers/Api/WorkSessionApiController.php` | `public_html/backsm.agrojurado.com/app/Http/Controllers/Api/WorkSessionApiController.php` | 🟡 IMPORTANTE |
| 9 | `clear_cache.php` | `public_html/backsm.agrojurado.com/clear_cache.php` | 🟢 UTILIDAD |

---

## 📋 Instrucciones Paso a Paso

### 1. Subir Archivos por FTP/cPanel

1. Abre **File Manager** en cPanel o usa **FileZilla**
2. Navega a: `public_html/backsm.agrojurado.com/`
3. Sube los archivos marcados como 🔴 CRÍTICO primero
4. Luego sube los archivos marcados como 🟡 IMPORTANTE
5. El archivo `clear_cache.php` es opcional (utilidad temporal)

### 2. Limpiar Cache

Accede desde tu navegador:
```
https://backsm.agrojurado.com/clear_cache.php
```

Deberías ver:
```
✅ Cache limpiado exitosamente
✅ Timezone configurada correctamente para Bogotá (UTC-5)
```

### 3. Verificar Timezone

Accede a:
```
https://backsm.agrojurado.com/api/verificar-hora
```

**Resultado esperado:**
```json
{
  "timezone_php": "America/Bogota",
  "carbon_now": "2026-03-16T21:00:00-05:00",
  "carbon_timezone": "America/Bogota",
  "offset": -5,
  "mysql_now": "2026-03-16 21:00:00",
  "mensaje": "Si offset es -5, está correcto para Bogotá"
}
```

### 4. Probar en la App

1. Abre la app Flutter
2. Inicia sesión
3. Crea una orden de trabajo
4. Verifica que la hora mostrada es la correcta (hora Bogotá)

### 5. Eliminar Archivo Temporal

**IMPORTANTE:** Después de verificar, elimina `clear_cache.php` por seguridad.

---

## ✅ Checklist de Verificación

- [ ] Archivos subidos al hosting
- [ ] Cache limpiado (clear_cache.php)
- [ ] Timezone verificada (/api/verificar-hora)
- [ ] Orden de trabajo creada
- [ ] Hora correcta en la app
- [ ] Archivo clear_cache.php eliminado

---

## 🆘 Solución de Problemas

### Error 404 en /api/verificar-hora
- Verifica que `routes/api.php` se subió correctamente
- Limpia el cache de rutas

### Error 500
- Revisa `storage/logs/laravel.log`
- Verifica permisos en carpetas `storage/` y `bootstrap/cache/`

### Timezone sigue incorrecta
- Verifica que `config/timezone.php` existe
- Limpia el cache de configuración
- Reinicia PHP-FPM si es posible

---

## 📞 Soporte

Si tienes problemas, comparte:
1. Captura de pantalla del error
2. Resultado de `/api/verificar-hora`
3. Logs de error (`storage/logs/laravel.log`)
