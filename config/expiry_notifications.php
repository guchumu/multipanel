<?php

declare(strict_types=1);

/**
 * Avisos de caducidad de usuarios media por Telegram.
 * Placeholders: {username}, {email}, {display_name}, {expires_at}, {expires_date}, {days_left}, {server_name}
 *
 * milestones: días restantes hasta caducar (0 = hoy, -1 = ayer caducó).
 */
return [
    'enabled' => env('EXPIRY_NOTIFICATIONS_ENABLED', true),
    'title' => 'Aviso de tu acceso',

    'milestones' => [10, 5, 3, 2, 1, 0, -1],

    'messages' => [
        10 => <<<'TXT'
Hola {display_name},

Te recordamos que tu acceso al servicio caduca en *10 días* ({expires_date}).

Si quieres renovar, contacta con nosotros antes de esa fecha para no perder el acceso.

Usuario: {username}
Servidor: {server_name}
TXT,
        5 => <<<'TXT'
Hola {display_name},

Quedan *5 días* para la caducidad de tu acceso ({expires_date}).

Renueva a tiempo para seguir disfrutando del contenido sin interrupciones.

Usuario: {username}
TXT,
        3 => <<<'TXT'
Hola {display_name},

*3 días* restantes hasta que expire tu acceso ({expires_date}).

Si necesitas ampliar la suscripción, escríbenos cuanto antes.

Usuario: {username}
TXT,
        2 => <<<'TXT'
Hola {display_name},

Tu acceso caduca en *2 días* ({expires_date}).

Evita quedarte sin servicio renovando antes del vencimiento.

Usuario: {username}
TXT,
        1 => <<<'TXT'
Hola {display_name},

*Mañana* caduca tu acceso ({expires_date}).

Este es el último aviso antes del corte. Contacta con nosotros si quieres renovar.

Usuario: {username}
TXT,
        0 => <<<'TXT'
Hola {display_name},

Tu acceso *caduca hoy* ({expires_date}).

Si no renuevas, el servicio se suspenderá al final del día.

Usuario: {username}
TXT,
        -1 => <<<'TXT'
Hola {display_name},

Tu acceso *caducó ayer* ({expires_date}) y el servicio ya no está activo.

Si deseas reactivarlo, contacta con nosotros para renovar.

Usuario: {username}
TXT,
    ],
];
