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
   - Recrea en el panel solo quienes estén en la biblioteca remota.
   - Al recrear, intenta recuperar email/Telegram/caducidad/notas de gemelos soft-deleted.
3. **Importar fechas/datos** (`plex_manager.sql`, modo overlay).
   - Solo filas con `servicio` / `service` **1** o **5** (o inferidos por nombre de servidor legacy / pagos).
   - 1 → Servitron / Server10, 5 → NucBox (match por nombre de servidor).
   - Actualiza **todas** las filas coincidentes (`plex_user_id`→`external_id`, email, username/`display_name`, o parte local del email), priorizando `on_server=1`.
   - Mapa columnas: `email`←`users.email`, `telegram_chat_id`←coalesce(`telegram_chat_id`,`telegram_id`), `expires_at`←`end_date`, `notes`←`private_notes`.
   - El flash muestra «Telegram escritos» (writes) **y** «BD tras import» (conteo real en `media_users`).

## Filtro servicio

| Código | Servidor destino (needles por defecto) |
|--------|----------------------------------------|
| 1 | `servitron`, `server10`, `server 10` |
| 5 | `nucbox`, `nuc box` |

Origen del código en cada fila SQL (en este orden):

1. Columna `servicio` o `service` en `users`
2. Último `payments_history.service` del mismo email
3. Nombre del servidor legacy (`servers.server_name`) si coincide con los needles

Dump típico plex_manager: `Nucbox` (id=1) → servicio 5; `Servitron` (id=2) → servicio 1.

Si tus servidores MultiPanel se llaman distinto, define en `.env`:

```env
IMPORT_SERVICIO_1_SERVERS=servitron,server10,server 10
IMPORT_SERVICIO_5_SERVERS=nucbox,nuc box
```

## Relacionado

## Comprobar usuarios uno a uno (biblioteca)

Tras wipe+sync+overlay, algunos usuarios pueden quedar sin fecha/Telegram o
marcados fuera del servidor. Flujo práctico:

1. **Forzar sincronización** en Usuarios Media (conserva filtros activos).
   - Reaplica el estado: **En biblioteca** / **No está**.
2. Filtra **Sin fecha**, **Sin Telegram** y/o **Fuera del servidor**.
3. Opción A — cola **Revisar** (`/media-users/revisar`):
   - Comprobar biblioteca → ver badge
   - Sigue en servidor (siguiente) / No está → Eliminar del panel / Siguiente
4. Opción B — ficha del usuario:
   - Badge grande + **Comprobar biblioteca**
   - Resultado claro: En biblioteca / No está
   - **Eliminar del panel** (soft-delete; no toca Plex/Jellyfin)
5. En filtro **Fuera del servidor**: botón masivo
   **Eliminar del panel (todos fuera del servidor)**.

Emails que no están en el dump legado no reciben fecha/Telegram por overlay:
rellénalos a mano en la ficha o comprueba si siguen en el servidor.

## SQL grande (FTP)

Si la subida por el navegador falla («Archivo demasiado grande» u otros errores de
`upload_max_filesize` / `post_max_size`):

1. Sube `plex_manager.sql` por FTP/SFTP a `storage/imports/` en el servidor.
2. En **Importar / Exportar** o en Limpieza, deja el file vacío y escribe
   `plex_manager.sql` en el campo «Archivo en servidor».
3. Importa en modo overlay (tras wipe+sync) o completo.

Límites orientativos en `public/.user.ini` (64M). En Plesk también puedes subir
`upload_max_filesize` y `post_max_size` en Configuración de PHP del dominio.
