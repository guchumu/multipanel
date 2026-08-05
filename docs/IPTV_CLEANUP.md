# Limpieza IPTV mezclado con Plex

Tras importar dumps de **plex_manager**, a veces usuarios IPTV quedan
asociados a un servidor Plex en MultiPanel. Esta herramienta ayuda a
identificarlos y retirarlos de forma segura.

## Dónde está

- UI: **Usuarios Media → Limpieza IPTV** (`/media-users/cleanup-iptv`)
- Servicio: `App\Services\IptvCleanupService`

## Heurística (score)

Un usuario aparece como candidato solo si el **score ≥ 2**. Señales:

| Señal | Puntos |
|-------|--------|
| `metadata.email_type` presente y ≠ `real` | +3 |
| Texto IPTV/xtream/m3u/bouquet/stalker en notas, username o email | +4 |
| `imported_from=plex_manager` y sin `external_id` | +3 |
| En servidor Plex sin ID remoto (refuerzo import) | +1 |
| Username solo numérico (≥4 dígitos) | +2 |
| Email sintético (`@iptv.`, `.local`, etc.) | +3 |
| Sin email ni external_id en Plex | +1 |

**Exclusión:** invitaciones Plex reales (`invited`/`pending` + `email_type=real`)
se omiten aunque falte `external_id`.

## Acciones (nunca hard-delete)

1. **Soft-delete** — pone `deleted_at` (desaparece de listados; recuperable en BD).
2. **Detach** — `server_id=null`, `external_id=null`, `status=suspended`.
   Conserva el registro y Telegram/vencimiento para reasignar después.

Ambas exigen escribir exactamente la frase de confirmación: `LIMPIAR IPTV`.

## Limitaciones

- Es heurística: **revisa la lista** antes de confirmar.
- No elimina la cuenta en el servidor Plex remoto (usa “Quitar del servidor”
  en la ficha si hace falta).
- Límite de escaneo: 2000 filas por consulta.
