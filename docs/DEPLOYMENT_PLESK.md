# Despliegue en Arsys + Plesk (Ubuntu 24.04)

Guía para VPS con **Plesk** y ~2 GB RAM. **No uses Docker** en este perfil (consume demasiada RAM).

## Datos de tu servidor

| Campo | Valor |
|-------|-------|
| IP | `212.227.98.60` |
| Dominio | `quizzical-beaver.212-227-98-60.plesk.page` |
| SO | Ubuntu 24.04 + Plesk |

## Paso 1 — Conectar por SSH

Desde tu Mac:

```bash
ssh root@212.227.98.60
```

(Si Arsys te dio otro usuario, úsalo: `ssh usuario@212.227.98.60`)

## Paso 2 — Comprobar recursos

```bash
free -h
php -v
mysql --version
plesk version
```

Con 2 GB RAM mantén `REDIS_ENABLED=false` (ya viene así en el script).

## Paso 3 — Configurar PHP en Plesk (panel web)

1. Entra a Plesk: `https://212.227.98.60:8443`
2. **Dominios** → `quizzical-beaver.212-227-98-60.plesk.page`
3. **Configuración de PHP** → PHP **8.3** (mínimo 8.2)
4. Extensiones activas: `mysql`, `curl`, `mbstring`, `openssl`, `json`, `zip`
5. **SSL/TLS** → Let's Encrypt → Obtener certificado (gratis)

## Paso 4 — Crear base de datos en Plesk

1. **Bases de datos** → **Añadir base de datos**
2. Nombre: `multipanel`
3. Usuario: `multipanel`
4. Contraseña: genera una segura y **guárdala**

## Paso 5 — Ejecutar script de despliegue

En SSH como root:

```bash
cd /tmp
git clone https://github.com/guchumu/multipanel.git
cd multipanel
chmod +x scripts/deploy-plesk.sh

# Opcional: pasa la clave de BD si ya la creaste
export DB_NAME=multipanel
export DB_USER=multipanel
export DB_PASS='TU_CLAVE_AQUI'

sudo bash scripts/deploy-plesk.sh
```

El script:
- Clona en `/var/www/vhosts/quizzical-beaver.212-227-98-60.plesk.page/multipanel`
- Configura `.env` con tu dominio
- Importa el schema SQL
- Apunta la raíz del sitio a `multipanel/public`
- Configura cron cada 5 minutos

## Paso 6 — Instalador web

Abre en el navegador:

```
https://quizzical-beaver.212-227-98-60.plesk.page/install/
```

Completa el asistente o usa el admin por defecto:

- Email: `admin@multipanel.local`
- Contraseña: `password` → **cámbiala al instante**

## Paso 7 — Verificar

```bash
php /var/www/vhosts/quizzical-beaver.212-227-98-60.plesk.page/multipanel/scripts/verify-install.php
```

Panel: `https://quizzical-beaver.212-227-98-60.plesk.page/dashboard`

## Si algo falla

| Error | Solución |
|-------|----------|
| 403 / 404 | Plesk → Alojamiento → Raíz del documento = `multipanel/public` |
| 500 | `tail -f storage/logs/multipanel.log` |
| BD connection | Revisa `.env` DB_* y que el usuario tenga permisos en Plesk |
| Permisos | `chown -R usuario-dominio:psacln multipanel && chmod -R 775 storage` |
| PHP antiguo | Cambia a PHP 8.3 en Plesk para este dominio |
| SQL import demasiado grande | FTP a `storage/imports/` + campo «Archivo en servidor»; o sube `upload_max_filesize`/`post_max_size` (ver `public/.user.ini`) |

## Opcional (cuando tengas más RAM o dominio final)

- **WebSocket**: `php scripts/websocket-server.php 8081` + proxy en Plesk
- **Redis**: activar en `.env` si instalas Redis
- **Dominio propio**: cambia `APP_URL` en `.env` y renueva SSL en Plesk

## Actualizar versión

```bash
cd /var/www/vhosts/quizzical-beaver.212-227-98-60.plesk.page/multipanel
git pull origin main
composer install --no-dev --optimize-autoloader
php cron/run.php migrate
```

También desde el panel: **Sistema → Actualizaciones → Importar actualizaciones**.
Con `AUTO_MIGRATE=true` (por defecto) las migraciones pendientes se aplican al cargar el panel tras el `git pull`.
