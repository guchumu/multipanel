<?php

declare(strict_types=1);

/**
 * Campaña de reenganche: invitar a volver / abrir una prueba corta.
 * Placeholders: {username}, {email}, {display_name}, {end_date}, {days},
 *               {days_left}, {server_name}, {trial_days}, {portal_url}
 */
return [
    'enabled' => env('REENGAGE_ENABLED', true),
    'interval_days' => (int) env('REENGAGE_INTERVAL_DAYS', 14),
    'max_sends' => (int) env('REENGAGE_MAX_SENDS', 4),
    'min_expired_days' => (int) env('REENGAGE_MIN_EXPIRED_DAYS', 3),
    'trial_days' => (int) env('REENGAGE_TRIAL_DAYS', 3),
    'title' => 'Te guardamos la plaza',
    'body' => <<<'TXT'
Hola {display_name},

Hace {days} días que tu plaza en {server_name} se quedó vacía. No la hemos ocupado: si quieres volver, sigue ahí.

Te proponemos *{trial_days} días de prueba* para retomar el hilo sin compromiso. Responde a este mensaje y te la abrimos.

Si ya lo tienes claro, entra al portal cuando quieras:
{portal_url}

Un saludo
TXT,
    'trial_title' => 'Prueba lista: {trial_days} días',
    'trial_body' => <<<'TXT'
Hola {display_name},

Hecho: te hemos abierto *{trial_days} días de prueba* en {server_name}. Tienes hasta el {end_date}.

Entra, mira un capítulo o una película, y si te encaja nos dices y te dejamos la plaza. Si no, se cierra sola. Sin letras pequeñas.

Portal: {portal_url}
TXT,
];
