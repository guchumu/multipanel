<?php

declare(strict_types=1);

/**
 * Códigos `servicio` / `service` del importador legacy (pagos / SQL)
 * y su servidor Plex/Jellyfin de destino en MultiPanel.
 *
 * Solo se aplican filas con estos códigos (el resto suele ser IPTV u otros packs).
 * El match de servidor es por nombre (case-insensitive, contiene el needle).
 * Si tus servidores se llaman distinto, ajusta los needles o las variables de entorno.
 */
return [
    /** Códigos permitidos al importar fechas/Telegram/email */
    'allowed' => [1, 5],

    /**
     * servicio => needles del nombre de servidor en MultiPanel.
     * 1 = Server10, 5 = NucBox (también acepta "Nucbox").
     * Match case-insensitive por contains (p.ej. Server10, server 10).
     */
    'map' => [
        1 => array_values(array_filter(array_map('trim', explode(',', (string) env('IMPORT_SERVICIO_1_SERVERS', 'server10,server 10'))))),
        5 => array_values(array_filter(array_map('trim', explode(',', (string) env('IMPORT_SERVICIO_5_SERVERS', 'nucbox,nuc box'))))),
    ],

    'labels' => [
        1 => 'Server10',
        5 => 'NucBox',
    ],
];
