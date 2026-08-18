# Reinicio limpio de usuarios media

Flujo para dejar el panel solo con usuarios reales de Plex/Jellyfin y
caducidades del importador (solo servicio 1 y 5).

## Dónde está

- UI: **Usuarios Media → Limpieza / reinicio** (`/media-users/limpieza`)
- Importar: `/import` (modo overlay o completo)
- Config: `config/import_servicio.php`

## Pasos

1. **Borrar todos los usuarios media** (confirmación `BORRAR TODOS`)
   - Soft-delete en la BD del panel (`deleted_at`).
   - **No** elimina cuentas en Plex/Jellyfin.
2. **Forzar sincronización** de cada servidor (o “todos”).
   - Recrea en el panel solo quienes están en la biblioteca remota.
3. **Importar fechas/datos** (`plex_manager.sql`, modo overlay).
   - Solo filas con `servicio` / `service` **1** o **5**.
   - 1 → Server10, 5 → NucBox (match por nombre de servidor).
   - Actualiza `expires_at`, Telegram, email sobre usuarios ya sync (email/username).

## Filtro servicio

| Código | Servidor destino (needles por defecto) |
|--------|----------------------------------------|
| 1 | `server10`, `server 10` |
| 5 | `nucbox`, `nuc box` |

Origen del código en cada fila SQL (en este orden):

1. Columna `servicio` o `service` en `users`
2. Último `payments_history.service` del mismo email
3. Nombre del servidor legacy (`servers.server_name`) si coincide con los needles

Si tus servidores se llaman distinto, define en `.env`:

```env
IMPORT_SERVICIO_1_SERVERS=server10,server 10
IMPORT_SERVICIO_5_SERVERS=nucbox,nuc box
```

## Relacionado
## SQL grande (FTP)

Si la subida por el navegador falla («Archivo demasiado grande» u otros errores de
`upload_max_filesize` / `post_max_size`):

1. Sube `plex_manager.sql` por FTP/SFTP a `storage/imports/` en el servidor.
2. En **Importar / Exportar** o en Limpieza, deja el file vacío y escribe
   `plex_manager.sql` en el campo «Archivo en servidor».
3. Importa en modo overlay (tras wipe+sync) o completo.

Límites orientativos en `public/.user.ini` (64M). En Plesk también puedes subir
`upload_max_filesize` y `post_max_size` en Configuración de PHP del dominio.
