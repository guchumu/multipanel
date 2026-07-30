# Manual de Usuario — MultiPanel ERP

## 1. Instalación

1. Ejecutar `composer install`
2. Copiar `.env.example` a `.env`
3. Acceder a `/install/` y seguir el asistente
4. Configurar cron: `*/5 * * * * php /ruta/cron/run.php all`

## 2. Panel de administración

### Dashboard
Vista general con usuarios activos, servidores online y gráficas. Actualización en tiempo real vía SSE.

### Servidores
- **Añadir**: Plex o Jellyfin con URL, puerto, token/API key
- **Sincronizar**: actualiza bibliotecas, sesiones y estado
- **Test**: comprueba conectividad

### Usuarios Media
Gestión de cuentas Plex/Jellyfin: crear, suspender, activar, eliminar. Límites de streams y fecha de expiración.

### Import/Export
- Importar CSV/JSON masivo de usuarios
- Plantilla CSV descargable en `/import/template`
- Exportar logs y estadísticas

## 3. Automatizaciones

Reglas predefinidas:
- Impago 5 días → suspender
- Suspendido 15 días → eliminar
- Pago confirmado → reactivar

Crear reglas personalizadas en `/automation`.

## 4. Facturación

- Crear planes (mensual, anual, vitalicio)
- Gestionar suscripciones y marcar pagos
- Portal cliente con Stripe/PayPal

## 5. Portal Cliente (`/portal`)

Los usuarios finales acceden con sus credenciales media para:
- Ver su suscripción
- Pagar online
- Abrir tickets de soporte
- Editar perfil

## 6. Integraciones

Soportadas: Sonarr, Radarr, Lidarr, Prowlarr, Bazarr, Tautulli, Overseerr, Ombi.

Añadir URL + API Key en `/integrations`. Test y stats en un clic.

## 7. API

### REST (`/api/v1`)
Autenticación JWT. Ver documentación en `/api/docs`.

### GraphQL (`/api/v1/graphql`)
```json
POST /api/v1/graphql
Authorization: Bearer {token}
{
  "query": "query dashboard { dashboard { usersActive serversOnline } }"
}
```

Schema: `/api/v1/graphql/schema`

## 8. Notificaciones

Configurar en `/settings`:
- **SMTP**: emails automáticos
- **Telegram**: bot token + chat ID
- **Discord**: webhook URL

## 9. Seguridad

- 2FA TOTP en Configuración → Seguridad
- CSRF en formularios web
- Rate limiting en API
- Auditoría completa en `/logs`

## 10. Plugins

Instalar extensiones en `/plugins` sin modificar el núcleo. Incluye plugin Telegram Bot de ejemplo.

## 11. Multi-empresa

Cambiar tenant activo en `/tenants` para gestionar paneles independientes.

## 12. Redis (opcional)

En `.env`:
```
REDIS_ENABLED=true
REDIS_HOST=127.0.0.1
```
Mejora rendimiento de cache y sesiones.

## 13. Diagnósticos y licencias

Acceder a `/diagnostics` para:
- Puntuación de salud del sistema (0-100%)
- Comprobaciones automáticas (PHP, BD, storage, Redis, SSL...)
- Activar licencia del panel

Generar claves de licencia:
```bash
php scripts/generate-license.php enterprise 365 tudominio.com
```

## 14. Tests y CI

```bash
composer test
```

GitHub Actions ejecuta tests automáticamente en cada push.

## 15. Despliegue producción

Ver guía completa en `docs/DEPLOYMENT.md`.

## 16. SSO OAuth (Google, GitHub, Microsoft)

En `.env`:
```
OAUTH_GOOGLE_ENABLED=true
OAUTH_GOOGLE_CLIENT_ID=tu-client-id
OAUTH_GOOGLE_CLIENT_SECRET=tu-secret
```

Redirect URI en el proveedor: `{APP_URL}/auth/oauth/google/callback`

## 17. Backups

Panel en `/backups` para crear, descargar y eliminar copias de la BD.

Copia remota opcional:
```
BACKUP_REMOTE_ENABLED=true
BACKUP_REMOTE_DRIVER=s3
BACKUP_S3_ENDPOINT=https://s3.amazonaws.com
BACKUP_S3_BUCKET=mi-bucket
```

## 18. Plugins y eventos

Los plugins pueden registrar hooks:
```php
listen('user.login', fn ($user) => $user);
listen('backup.created', fn ($data) => $data);
```

Eventos disponibles: `user.login`, `job.completed`, `backup.created`

## 19. CRM Clientes

Gestión de clientes en `/customers`:
- Crear, buscar y editar clientes
- Vincular con usuarios media
- Ver suscripciones asociadas

## 20. Webhooks salientes

Configura endpoints en `/webhooks` para recibir eventos en sistemas externos.
Firma HMAC en header `X-MultiPanel-Signature`.

## 21. Métricas Prometheus

Endpoint: `GET /metrics` (formato Prometheus)

Protección opcional:
```
METRICS_TOKEN=tu-token-secreto
Authorization: Bearer tu-token-secreto
```

## 22. Idiomas

Selector en la barra superior (ES / EN).
Archivos de traducción en `resources/lang/`.

## 23. Privacidad GDPR

Panel `/privacy`:
- Exportar datos personales (JSON)
- Solicitar eliminación (derecho al olvido)

## 24. Roles y permisos (RBAC)

Panel `/roles` para asignar permisos a cada rol.
Helper en código: `can('billing.manage')`

## 25. API Keys y webhooks entrantes

Generar keys en `/api-keys`, usar header:
```
X-API-Key: mp_xxxxxxxx
POST /api/v1/hooks/payment.completed
{"amount": 9.99, "reference": "ABC123"}
```

## 26. Facturas

Listado en `/invoices`. Al marcar suscripción como pagada se genera factura HTML imprimible (Ctrl+P → PDF).

## 27. Grafana

Importar dashboard: `docker/grafana/multipanel-dashboard.json`
Scrape target: `http://multipanel/metrics`

## 28. WebSocket tiempo real

```bash
php scripts/websocket-server.php 8081
# Cliente: ws://localhost:8081?channel=dashboard
```

Fallback long-polling: `GET /api/v1/events/poll?since=0&timeout=15`

## 29. Seguridad avanzada

Panel `/security`:
- Bloqueo de IPs
- Políticas ABAC en `config/abac.php`
- Headers de seguridad HTTP globales

## 30. Health check

```bash
php scripts/health-check.php
# Exit 0 = OK (para load balancers/monitoring)
```

## 31. Backups incrementales

En `/backups` → botón **Incremental** (solo tablas de logs/stats desde último backup).

---

**Soporte**: Abrir ticket en `/tickets` o consultar logs en `/logs`.
