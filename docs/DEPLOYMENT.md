# Despliegue en producción — MultiPanel ERP

## Requisitos del servidor

- Ubuntu 22.04+ / Debian 12+
- PHP 8.3-FPM con extensiones: pdo_mysql, json, openssl, mbstring, curl
- MySQL 8.0+
- Nginx 1.24+
- Redis 7+ (recomendado)
- Composer 2.x
- Certbot (SSL Let's Encrypt)

## Opción A: Docker (recomendado)

```bash
cd docker
cp ../.env.example ../.env
# Editar .env con credenciales de producción

docker compose -f docker-compose.prod.yml up -d
```

Servicios incluidos:
| Servicio | Puerto | Descripción |
|----------|--------|-------------|
| nginx | 80/443 | Reverse proxy + SSL |
| app | — | PHP-FPM |
| db | — | MySQL 8 |
| redis | — | Cache/sessions |
| websocket | 8081 | Tiempo real |
| prometheus | 9090 | Métricas |
| grafana | 3000 | Dashboards |
| cron | — | Tareas cada 5 min |

```bash
# Verificar instalación
docker compose -f docker-compose.prod.yml exec app php scripts/verify-install.php
```

## Opción B: Instalación manual

### 1. Clonar y configurar

```bash
git clone <repo> /var/www/multipanel
cd /var/www/multipanel
make install          # o: bash scripts/install.sh
make verify           # comprobar instalación
```

### 2. Base de datos

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p multipanel < database/seeds/default.sql
```

### 3. Permisos

```bash
chown -R www-data:www-data storage public
chmod -R 775 storage
```

### 4. Nginx

```bash
cp docker/nginx/multipanel.conf /etc/nginx/sites-available/multipanel
ln -s /etc/nginx/sites-available/multipanel /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

### 5. SSL con Certbot

```bash
certbot --nginx -d multipanel.example.com
```

### 6. Cron

```crontab
*/5 * * * * www-data php /var/www/multipanel/cron/run.php all >> /var/log/multipanel-cron.log 2>&1
# Backup: incluido en `all` con gate cada 6h (BACKUP_INTERVAL_HOURS) y retención ~28 archivos
# Opcional forzar: 0 3 * * * www-data php /var/www/multipanel/cron/run.php backup >> /var/log/multipanel-backup.log 2>&1
```

### 7. PHP-FPM tuning (producción)

```ini
; /etc/php/8.3/fpm/pool.d/multipanel.conf
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
```

## Variables .env críticas

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://multipanel.example.com
SESSION_SECURE=true

REDIS_ENABLED=true
REDIS_HOST=127.0.0.1

STRIPE_SECRET_KEY=sk_live_...
PAYPAL_CLIENT_ID=...
PAYPAL_SANDBOX=false
```

## Post-instalación

1. Acceder a `/install/` o crear admin manualmente
2. Ir a `/diagnostics` — verificar 100% salud
3. Activar licencia en `/diagnostics`
4. Configurar SMTP y Telegram en `/settings`
5. Ejecutar migraciones en `/updater`
6. Configurar cron
7. Eliminar o proteger `/public/install/` en producción

## Seguridad

- Cambiar credenciales por defecto
- Activar 2FA para administradores
- Configurar firewall (ufw): solo 80, 443, 22
- Backups automáticos diarios
- Monitorizar `/diagnostics` periódicamente

## CI/CD

GitHub Actions ejecuta tests en cada push/PR (`.github/workflows/ci.yml`).

```bash
composer test          # Ejecutar tests localmente
composer test:coverage # Con cobertura HTML
```

## Troubleshooting

| Problema | Solución |
|----------|----------|
| 500 error | Revisar `storage/logs/multipanel.log` |
| SSE no funciona | Desactivar `proxy_buffering` en Nginx |
| Redis fallback | Verificar `REDIS_ENABLED=true` y extensión php-redis |
| Permisos | `chmod -R 775 storage` |
