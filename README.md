# MultiPanel ERP

Plataforma profesional de gestión centralizada para servidores **Plex** y **Jellyfin**. ERP completo con multiusuario, multiservidor, facturación, automatizaciones, API REST y panel de administración moderno.

## Stack tecnológico

| Capa | Tecnología |
|------|-----------|
| Backend | PHP 8.3+, MVC propio, PSR-4 |
| Base de datos | MySQL 8 |
| Frontend | Bootstrap 5.3, Chart.js, ES2023 |
| Auth | JWT + Sessions + Argon2id |
| HTTP Client | Guzzle 7 |
| Logs | Monolog 3 |
| Contenedores | Docker + Apache |

## Requisitos

- PHP >= 8.3 con extensiones: pdo_mysql, openssl, mbstring, json, curl
- MySQL >= 8.0
- Composer 2.x
- Apache con mod_rewrite (o Nginx equivalente)

## Instalación rápida

```bash
# Clonar e instalar dependencias
composer install

# Configurar entorno
cp .env.example .env

# Opción A: Instalador web
# Acceder a http://localhost/install/

# Opción B: Manual
mysql -u root -p < database/schema.sql
# Editar .env con credenciales DB
# Generar claves:
php -r "echo bin2hex(random_bytes(32));"
```

## Docker

```bash
cd docker
docker compose up -d
# Panel: http://localhost:8080
# Instalador: http://localhost:8080/install/
```

## Cron Jobs

```bash
# Cada 5 minutos - sincronización y automatizaciones
*/5 * * * * php /path/to/multipanel/cron/run.php all

# Backup diario a las 3:00
0 3 * * * php /path/to/multipanel/cron/run.php backup
```

## Estructura del proyecto

```
multipanel/
├── app/
│   ├── Controllers/     # Controladores web y API
│   ├── Models/          # Modelos Active Record
│   ├── Repositories/    # Capa de acceso a datos
│   ├── Services/        # Lógica de negocio
│   │   └── Media/       # Integraciones Plex/Jellyfin
│   └── Middleware/       # Auth, CSRF, JWT, Rate Limit
├── core/                # Framework MVC
├── config/              # Configuración
├── database/            # Schema SQL y migraciones
├── public/              # Document root
├── resources/views/     # Plantillas PHP
├── routes/              # Rutas web y API
├── storage/             # Logs, cache, backups
├── cron/                # Tareas programadas
└── docker/              # Contenedores
```

## API REST

Base URL: `/api/v1`

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/health` | Health check |
| POST | `/auth/login` | Autenticación JWT |
| POST | `/auth/refresh` | Renovar token |
| GET | `/dashboard` | Estadísticas |
| GET/POST/DELETE | `/servers` | CRUD servidores |
| POST | `/servers/{uuid}/sync` | Sincronizar servidor |

| GET/POST/PATCH/DELETE | `/users` | CRUD usuarios media |

Autenticación: `Authorization: Bearer {token}`

Documentación interactiva: `/api/docs`

## Módulos implementados

- [x] Core MVC framework
- [x] Autenticación (Session + JWT + 2FA TOTP)
- [x] Dashboard con Chart.js
- [x] Gestión de servidores Plex/Jellyfin
- [x] Gestión de usuarios media
- [x] API REST v1 + OpenAPI/Swagger
- [x] Integración Plex API
- [x] Integración Jellyfin API
- [x] Sincronización automática (cron)
- [x] Auditoría de acciones + export CSV
- [x] Instalador web
- [x] Docker ready
- [x] Notificaciones (Email, Telegram, Discord, Webhook)
- [x] Motor de automatizaciones
- [x] Facturación base (planes, suscripciones, facturas)
- [x] Configuración (SMTP, Telegram, 2FA)
- [x] Portal cliente autoservicio (`/portal`)
- [x] Pagos Stripe + PayPal
- [x] Estadísticas avanzadas + export CSV
- [x] Sistema tickets soporte (admin + portal)
- [x] Integraciones *arr (Sonarr, Radarr, Tautulli, Overseerr)
- [x] Actualizador con migraciones
- [x] Dashboard auto-refresh (30s)
- [x] GraphQL API (`/api/v1/graphql`)
- [x] SSE tiempo real (`/stream/events`)
- [x] Redis cache (opcional)
- [x] Sistema plugins extensible
- [x] Lidarr, Prowlarr, Bazarr, Ombi
- [x] Import masivo CSV/JSON
- [x] Multi-tenant UI (`/tenants`)
- [x] Manual de usuario (`docs/USER_MANUAL.md`)
- [x] Tests PHPUnit + CI GitHub Actions
- [x] Panel diagnósticos + licencias (`/diagnostics`)
- [x] Despliegue producción Nginx + Docker (`docs/DEPLOYMENT.md`)
- [x] SSO OAuth2/OIDC (Google, GitHub, Microsoft)
- [x] Sistema eventos/hooks para plugins
- [x] Backups BD + remoto S3/webhook (`/backups`)
- [x] Cola de trabajos async (`JobProcessor`)
- [x] CRM clientes (`/customers`)
- [x] Pagos Bizum + Crypto en portal
- [x] Webhooks salientes (`/webhooks`)
- [x] Métricas Prometheus (`/metrics`)
- [x] i18n ES/EN con selector
- [x] Panel GDPR (`/privacy`)
- [x] RBAC roles/permisos + ABAC (`/roles`)
- [x] API Keys + webhooks entrantes
- [x] Facturas HTML imprimibles (`/invoices`)
- [x] Dashboard Grafana preconfigurado
- [x] Tests integración BD
- [x] PDF nativo + WebSocket server
- [x] Seguridad global + IP blacklist
- [x] ABAC policy engine
- [x] i18n completo sidebar
- [x] Backups incrementales
- [x] Health check script

## Release 1.1.0

```bash
make install    # Instalación completa
make verify     # Verificar instalación
make test       # PHPUnit
make docker-up  # Stack producción
make ws         # WebSocket server
```

Ver [CHANGELOG.md](CHANGELOG.md) para historial completo.

## Licencia

Proprietary - Todos los derechos reservados.
