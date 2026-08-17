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

    // Escalado servidor caído (minutos desde la primera detección). Tras el último, no se reenvía.
    'server_down_escalation_minutes' => [0, 5, 15, 30],
];
