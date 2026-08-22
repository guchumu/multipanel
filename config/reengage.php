<?php

declare(strict_types=1);

/**
 * Campaña de reenganche: 4 avisos en orden + mensaje al abrir prueba.
 * Placeholders: {username}, {email}, {display_name}, {end_date}, {days_left},
 *               {server_name}, {service_name}, {trial_days}, {discount_percent},
 *               {portal_url}, {link_years}
 */
return [
    'enabled' => env('REENGAGE_ENABLED', true),
    'interval_days' => (int) env('REENGAGE_INTERVAL_DAYS', 14),
    'max_sends' => (int) env('REENGAGE_MAX_SENDS', 4),
    // Solo tras ~2 meses caducado (antes: avisos de renovación a 15/30/45 días).
    'min_expired_days' => (int) env('REENGAGE_MIN_EXPIRED_DAYS', 60),
    'trial_days' => (int) env('REENGAGE_TRIAL_DAYS', 3),
    'discount_percent' => (int) env('REENGAGE_DISCOUNT_PERCENT', 15),
    'link_ttl_days' => (int) env('REENGAGE_LINK_TTL_DAYS', 365),
    'invites' => [
        [
            'label' => 'Capítulo a medias',
            'title' => 'Se te quedó a medias en Plex',
            'body' => <<<'TXT'
Hola {display_name},

Hace tiempo que no pasas por *Plex* ({server_name}). Tu historial sigue ahí: lo último que dejaste no se ha tocado.

Si vuelves, te hacemos un *{discount_percent}% de descuento* en la renovación. También podemos abrirte *{trial_days} días de prueba* sin compromiso: responde a este mensaje y te la dejamos lista.

Enlace directo (sin contraseña, válido *{link_years} año*):
{portal_url}

Ahí entras al portal. Para ver series y pelis, abre la app de *Plex* con tu usuario de siempre.
TXT,
        ],
        [
            'label' => 'Sin compromiso',
            'title' => 'Por si te apetece volver a Plex',
            'body' => <<<'TXT'
Hola {display_name},

Ya hace bastante que no te vemos en *Plex*. Sin presiones: si ahora no es el momento, este mensaje se puede ignorar.

Si sí te apetece, hay *{trial_days} días de prueba* y, si te quedas, *{discount_percent}% de descuento* en la renovación. Responde “prueba” y te la abrimos.

Enlace directo (sin contraseña, válido *{link_years} año*):
{portal_url}

Para ver contenido: app de *Plex* con tu usuario habitual.
TXT,
        ],
        [
            'label' => 'Te echamos en falta',
            'title' => 'Te echamos en falta en Plex',
            'body' => <<<'TXT'
Hola {display_name},

Te echamos en falta en *Plex* ({server_name}). Tu plaza sigue libre: no la hemos ocupado.

*{trial_days} días de prueba* para volver sin compromiso, y si renuevas te aplicamos un *{discount_percent}% de descuento*. Responde y te abrimos la prueba.

Enlace directo (sin contraseña, válido *{link_years} año*):
{portal_url}
TXT,
        ],
        [
            'label' => 'Cerramos el hilo',
            'title' => 'Último toque · Plex',
            'body' => <<<'TXT'
Hola {display_name},

Este es el último toque. Si quieres volver a *Plex*, *{trial_days} días de prueba* y un *{discount_percent}% de descuento* si te quedas. Si no, no pasa nada: se cierra aquí y dejamos de escribir.

Enlace directo (sin contraseña, válido *{link_years} año*):
{portal_url}
TXT,
        ],
    ],
    'trial_title' => 'Prueba Plex lista: {trial_days} días',
    'trial_body' => <<<'TXT'
Hola {display_name},

Hecho: te hemos abierto *{trial_days} días de prueba* en *Plex* ({server_name}). Tienes hasta el {end_date}.

Abre la app de *Plex* con tu usuario de siempre y dale al play. Si te encaja, renueva con *{discount_percent}% de descuento*. Si no, se cierra sola. Sin letras pequeñas.

Portal (sin contraseña, válido *{link_years} año*):
{portal_url}
TXT,
];
