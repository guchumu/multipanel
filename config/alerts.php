<?php

declare(strict_types=1);

/**
 * Alertas admin (servidor caído, etc.).
 * Prioridad: settings UI (grupo alerts) → variables de entorno → defaults.
 */
return [
    'email' => env('ALERT_EMAIL', 'alex@masquecero.es'),

    // CallMeBot WhatsApp (gratis / barato): https://www.callmebot.com/blog/free-api-whatsapp-messages/
    // 1) Añade el contacto de CallMeBot y envía: I allow callmebot to send me messages
    // 2) Recibirás el apikey (a veces ~24h); guárdalo en Configuración → WhatsApp / Alertas admin
    'whatsapp_enabled' => env('WHATSAPP_ALERTS_ENABLED', false),
    'whatsapp_phone' => env('WHATSAPP_CALLMEBOT_PHONE', env('WHATSAPP_PHONE', '')),
    'whatsapp_apikey' => env('WHATSAPP_CALLMEBOT_APIKEY', env('WHATSAPP_APIKEY', '')),
    'whatsapp_api_url' => env('WHATSAPP_CALLMEBOT_URL', 'https://api.callmebot.com/whatsapp.php'),

    // Avisos a clientes (portal). Distinto del CallMeBot del admin.
    'whatsapp_cloud_token' => env('WHATSAPP_CLOUD_TOKEN', ''),
    'whatsapp_cloud_phone_id' => env('WHATSAPP_CLOUD_PHONE_ID', ''),
    'whatsapp_cloud_display_phone' => env('WHATSAPP_CLOUD_DISPLAY_PHONE', ''),
    'whatsapp_cloud_verify_token' => env('WHATSAPP_CLOUD_VERIFY_TOKEN', ''),
    'whatsapp_client_alerts' => true,

    // Preferencias por evento (DB settings.group=alerts tiene prioridad).
    // WhatsApp: digest + server-down + alta ON; renovación OFF (menos spam CallMeBot).
    // Si la clave ya está en DB como 0, se respeta; solo aplica si falta la clave.
    'whatsapp_notify_alta' => true,
    'whatsapp_notify_renew' => false,
    'whatsapp_notify_server_down' => true,
    'whatsapp_notify_digest' => true,
    'telegram_notify_alta' => true,
    'telegram_notify_renew' => true,
    'telegram_notify_server_down' => true,
    'telegram_notify_digest' => true,
    'email_notify_server_down' => true,

    // Eventos críticos (sync FAIL, cron, backup, streams, Stripe…). Default ON.
    'telegram_notify_critical' => true,
    'whatsapp_notify_critical' => true,
    'email_notify_critical' => true,

    // ntfy (https://ntfy.sh o servidor propio). DB settings.group=alerts tiene prioridad.
    'ntfy_enabled' => env('NTFY_ENABLED', false),
    'ntfy_server' => env('NTFY_SERVER', 'https://ntfy.sh'),
    'ntfy_topic' => env('NTFY_TOPIC', ''),
    'ntfy_token' => env('NTFY_TOKEN', ''),
    'ntfy_notify_alta' => true,
    'ntfy_notify_renew' => false,
    'ntfy_notify_server_down' => true,
    'ntfy_notify_digest' => true,
    'ntfy_notify_critical' => true,

    // Escalado servidor caído (minutos desde la primera detección). Tras el último, no se reenvía.
    'server_down_escalation_minutes' => [0, 5, 15, 30],
];
