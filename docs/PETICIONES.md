# Peticiones (BD remota legacy)

MultiPanel puede gestionar las **peticiones de contenido** del panel antiguo leyendo/escribiendo la misma base MySQL remota (`peticiones` / `motivo`), sin migrar el esquema. El panel legacy sigue funcionando en paralelo.

## Configuración

1. En el panel: **Configuración → pestaña «Peticiones / BD remota»**
2. Indica:
   - Host (ej. `servidor.masquecero.net`)
   - Puerto `3306`
   - Base de datos (ej. `series`)
   - Usuario (ej. `user_series`)
   - Contraseña (se guarda cifrada con SecretCrypt; no va a Git)
3. Pulsa **Probar conexión**.
4. Opcional: clave TMDb v3 (carátulas + plataformas de streaming en España). Vacío = se omiten.
5. Opcional en `.env` local (gitignored):

```env
PETICIONES_DB_HOST=servidor.masquecero.net
PETICIONES_DB_PORT=3306
PETICIONES_DB_DATABASE=series
PETICIONES_DB_USERNAME=user_series
PETICIONES_DB_PASSWORD=
PETICIONES_TMDB_API_KEY=
```

La UI de settings tiene prioridad sobre `.env` cuando hay valores guardados.

La conexión PDO remota usa siempre **utf8mb4** (`charset` en el DSN + `SET NAMES utf8mb4`) para que tildes y ñ se muestren bien. Las vistas escapan con `e()` → `htmlspecialchars(..., UTF-8)` y las respuestas JSON van con `charset=utf-8`.

## Menú

**Peticiones** en el menú lateral (junto a importación / gestión).

Pestañas: Pendientes · En proceso · Denegadas · Todas.

Acciones: aceptar, subir, denegar (+ motivo), borrar, editar título, añadir URL manual, actualizar carátulas TMDb.

Avisos Telegram al aceptar / denegar / subir usando `idusuario` como chat id (mismo bot de Configuración → Telegram).

## Carátulas y plataformas (TMDb)

Si hay clave TMDb, el listado busca el título (`search/multi`, idioma `es-ES`) y:

- Usa el póster de TMDb (`image.tmdb.org/t/p/w500`) cuando el campo `img` de la BD está vacío, es un placeholder o no es una URL http(s).
- Muestra logos de proveedores de suscripción en España (`watch/providers` → `ES.flatrate`).
- Guarda la carátula en `img` para no repetir la consulta.
- Cachea cada título 12 horas. La primera visita carga las que faltan en segundo plano (sin bloquear la página). El botón **Actualizar carátulas** fuerza un recálculo de la página actual.

**No** guardes la API key en Git: solo Configuración o `.env`.

## Firewall / MySQL remoto

El servidor MySQL (`servidor.masquecero.net:3306`) debe **permitir conexiones desde la IP pública del VPS donde corre MultiPanel**.

En el host MySQL (o su firewall / `bind-address` / grants):

- Abrir TCP **3306** hacia la IP del VPS MultiPanel.
- `GRANT` al usuario (`user_series`) desde ese host remoto, no solo `localhost`.

Si el legacy usaba `localhost` en esa máquina, MultiPanel **no** puede usar localhost: debe conectar por red al hostname público/interno que exponga MySQL.

## Seguridad

- **Nunca** subir contraseñas, API keys ni `.env` a Git.
- Si una contraseña se compartió por chat, **rótala** en MySQL y actualízala en Configuración.
- Prepared statements en todas las consultas remotas.

## Fuera de alcance (MVP)

- Sonarr/Radarr automático (stub futuro).
- ScraperAPI / scrape de Filmaffinity: por ahora alta manual de título + URL + imagen.
