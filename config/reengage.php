<?php

declare(strict_types=1);

/**
 * Campaña de reenganche: 4 avisos en orden + mensaje al abrir prueba.
 * Placeholders: {username}, {email}, {display_name}, {end_date}, {days_left},
 *               {server_name}, {service_name}, {trial_days}, {discount_percent},
 *               {year_price}, {discounted_price}, {renew_label}, {payment_url}, {portal_url}, {link_years}
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

Hace tiempo que no pasas por *Plex* ({server_name}). Tu historial sigue ahí.

Renueva *{renew_label}* con un *{discount_percent}% de descuento* (solo para ti, esta vez): *{discounted_price} €* en lugar de {year_price} €.

Paga aquí y activamos tu acceso al momento. Si no te encaja, ignora el mensaje:
{payment_url}

Para ver: app de *Plex* con tu usuario de siempre.
TXT,
        ],
        [
            'label' => 'Sin compromiso',
            'title' => 'Por si te apetece volver a Plex',
            'body' => <<<'TXT'
Hola {display_name},

Ya hace bastante que no te vemos en *Plex*. Sin presiones: si ahora no es el momento, ignora esto.

Si sí te apetece volver: *{renew_label}* por *{discounted_price} €* (precio normal {year_price} €, −{discount_percent}% solo esta vez).

Enlace de pago:
{payment_url}
TXT,
        ],
        [
            'label' => 'Te echamos en falta',
            'title' => 'Te echamos en falta en Plex',
            'body' => <<<'TXT'
Hola {display_name},

Te echamos en falta en *Plex* ({server_name}). Tu plaza sigue libre.

*{renew_label}* con *{discount_percent}% de descuento*: *{discounted_price} €* (antes {year_price} €). Un solo toque:
{payment_url}
TXT,
        ],
        [
            'label' => 'Cerramos el hilo',
            'title' => 'Último toque · Plex',
            'body' => <<<'TXT'
Hola {display_name},

Último toque. Si quieres volver a *Plex*, *{renew_label}* por *{discounted_price} €* (−{discount_percent}%, precio normal {year_price} €).

{payment_url}

Si no, cerramos aquí y no molestamos más.
TXT,
        ],
    ],
    'trial_title' => 'Prueba Plex lista: {trial_days} días',
    'trial_body' => <<<'TXT'
Hola {display_name},

Hecho: *{trial_days} días de prueba* en *Plex* ({server_name}), hasta el {end_date}.

Abre la app de *Plex* con tu usuario de siempre. Si te encaja, renueva con descuento aquí:
{payment_url}
TXT,
];
