<?php

declare(strict_types=1);

/**
 * Avisos de caducidad de usuarios media por Telegram.
 * Placeholders: {username}, {email}, {display_name}, {expires_at}, {expires_date},
 *               {end_date}, {days}, {days_left}, {server_name}
 *
 * milestones: días restantes hasta caducar (0 = hoy, -1 = ayer caducó).
 */
return [
    'enabled' => env('EXPIRY_NOTIFICATIONS_ENABLED', true),
    'title' => 'Aviso de tu acceso',
    'notify_admin' => env('EXPIRY_NOTIFY_ADMIN', true),
    'deactivate_on_expiry' => env('EXPIRY_DEACTIVATE_USER', true),

    'milestones' => [10, 7, 5, 4, 3, 2, 1, 0, -1],

    'messages' => [
        10 => <<<'TXT'
Hola {display_name},

Te recordamos que tu acceso al servicio caduca en *10 días* ({end_date}).

Si quieres renovar, contacta con nosotros antes de esa fecha para no perder el acceso.

Usuario: {username}
Servidor: {server_name}
TXT,
        7 => <<<'TXT'
Hola {display_name},

Quedan *7 días* para la caducidad de tu acceso ({end_date}).

Renueva a tiempo para seguir disfrutando del contenido sin interrupciones.

Usuario: {username}
TXT,
        5 => <<<'TXT'
Hola {display_name},

Quedan *5 días* para la caducidad de tu acceso ({end_date}).

Renueva a tiempo para seguir disfrutando del contenido sin interrupciones.

Usuario: {username}
TXT,
        4 => <<<'TXT'
Hola {display_name},

Quedan *4 días* para la caducidad de tu acceso ({end_date}).

Si necesitas ampliar la suscripción, escríbenos cuanto antes.

Usuario: {username}
TXT,
        3 => <<<'TXT'
Hola {display_name},

*3 días* restantes hasta que expire tu acceso ({end_date}).

Si necesitas ampliar la suscripción, escríbenos cuanto antes.

Usuario: {username}
TXT,
        2 => <<<'TXT'
Hola {display_name},

Tu acceso caduca en *2 días* ({end_date}).

Evita quedarte sin servicio renovando antes del vencimiento.

Usuario: {username}
TXT,
        1 => <<<'TXT'
Hola {display_name},

*Mañana* caduca tu acceso ({end_date}).

Este es el último aviso antes del corte. Contacta con nosotros si quieres renovar.

Usuario: {username}
TXT,
        0 => <<<'TXT'
Hola {display_name},

Tu acceso *caduca hoy* ({end_date}).

Si no renuevas, el servicio se suspenderá al final del día.

Usuario: {username}
TXT,
        -1 => <<<'TXT'
Hola {display_name},

Tu acceso *caducó ayer* ({end_date}) y el servicio ya no está activo.

Si deseas reactivarlo, contacta con nosotros para renovar.

Usuario: {username}
TXT,
    ],
];
