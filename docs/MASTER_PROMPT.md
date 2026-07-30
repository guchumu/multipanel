# MASTER PROMPT — MultiPanel ERP

> Prompt de especificación de arquitectura para generación de aplicación profesional Plex/Jellyfin ERP.

## Roles del LLM

Actúa como equipo completo: Software Architect, Senior PHP Developer, Senior MySQL DBA, Backend Engineer, Frontend Bootstrap Expert, DevOps Engineer, UX/UI Designer, Security Engineer, API Designer.

## Objetivo

Panel web profesional, multiusuario, multiservidor, multiplataforma. Preparado para decenas de servidores y cientos de usuarios. **Producto listo para producción**, no MVP.

## Stack obligatorio

PHP 8.3+ · MySQL 8 · Bootstrap 5.3 · JavaScript ES2023 · AJAX · Fetch API · Chart.js · Composer · MVC propio · PDO · REST API · JSON · JWT · Cron Jobs · Docker Ready · Apache/Nginx

**Prohibido:** Laravel, Symfony u otros frameworks pesados.

## Entregables por fases

1. Arquitectura general ✅
2. Estructura de carpetas ✅
3. Modelo ER ✅
4. Script SQL completo ✅
5. Sistema MVC ✅
6. Autenticación ✅
7. Gestión usuarios ✅
8. Gestión servidores ✅
9. Integración Plex ✅
10. Integración Jellyfin ✅
11. API REST ✅ (completa v1 + OpenAPI)
12. Automatizaciones ✅ (motor de reglas)
13. Notificaciones ✅ (Email, Telegram, Discord, Webhook)
14. Dashboard ✅
15. Estadísticas ⏳
16. Backups ✅ (cron + registro BD)
17. Instalador ✅
18. Actualizador ⏳
19. Documentación técnica ✅
20. Manual de usuario ⏳

### Fase 5 completada
- Tests PHPUnit (Password, 2FA, Validator, GraphQL, License) ✅
- CI/CD GitHub Actions ✅
- Panel diagnósticos + licencias ✅
- Nginx producción + Docker Compose prod ✅
- Documentación despliegue ✅

### Fase 6 completada
- SSO OAuth2/OIDC (Google, GitHub, Microsoft) ✅
- Sistema eventos/hooks para plugins ✅
- BackupService + panel `/backups` + S3/webhook remoto ✅
- JobProcessor con cola async ✅
- Bloqueo instalador post-setup ✅

### Fase 7 completada
- CRM clientes (`/customers`) ✅
- Pagos Bizum + Crypto ✅
- Webhooks salientes (`/webhooks`) ✅
- Métricas Prometheus (`/metrics`) ✅
- i18n ES/EN + selector idioma ✅
- Panel GDPR (`/privacy`) ✅

### Fase 8 completada
- RBAC roles/permisos (`/roles`) + ABAC tenant ✅
- API Keys (`/api-keys`) ✅
- Webhooks entrantes (`POST /api/v1/hooks/{event}`) ✅
- Facturas HTML/PDF (`/invoices`) ✅
- EventHub SSE ampliado ✅
- Dashboard Grafana (`docker/grafana/`) ✅
- Tests integración BD ✅

### Fase 9 completada
- PDF nativo facturas (`SimplePdf`) ✅
- WebSocket server (`scripts/websocket-server.php`) ✅
- Long-polling API (`/api/v1/events/poll`) ✅
- Seguridad global + IP blacklist (`/security`) ✅
- ABAC policy engine (`config/abac.php`) ✅
- i18n completo sidebar ES/EN ✅
- Backups incrementales ✅
- Health check script (`scripts/health-check.php`) ✅
- AuditService centralizado ✅

### Fase 10 — Release 1.1.0 ✅
- Stack Docker completo (Prometheus + Grafana + WebSocket) ✅
- Scripts install.sh + verify-install.php ✅
- Makefile comandos devops ✅
- API REST customers/invoices ✅
- Cliente realtime unificado (WS→SSE→poll) ✅
- CI con tests integración BD ✅
- CHANGELOG + versión 1.1.0 ✅

## Módulos ERP extendidos (roadmap)

- Facturación: Stripe, PayPal, Bizum, crypto
- CRM clientes
- Tautulli, Overseerr, Ombi, Sonarr, Radarr, Lidarr, Prowlarr, Bazarr
- Tickets soporte + portal autoservicio
- Multiempresa (tenants)
- Sistema plugins
- Cola trabajos async
- GraphQL + REST
- SSO OAuth2/OIDC
- Prometheus + Grafana
- ABAC + RBAC
- Backups incrementales S3/Backblaze/GDrive
- Licencias del panel
- i18n completo
- Webhooks bidireccionales
- Auditoría GDPR

## Reglas de generación

- Código completo, funcional, producción. Sin pseudocódigo.
- PSR-12, PHPDoc, SOLID, DRY, KISS, Clean Architecture
- Si se alcanza límite de contexto: detener al final de un archivo y esperar **"Continuar"**
- Mantener coherencia con todo lo generado anteriormente

## Ubicación del proyecto

```
Desktop/PROGRAMACION/multipanel/
```
