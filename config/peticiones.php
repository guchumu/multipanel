<?php

declare(strict_types=1);

/**
 * Conexión remota a la BD legacy de peticiones (tablas `peticiones` / `motivo`).
 * Prioridad: settings UI (grupo peticiones, password cifrado) → variables de entorno.
 * NUNCA hardcodear contraseñas aquí.
 */
return [
    'host' => env('PETICIONES_DB_HOST', ''),
    'port' => (int) env('PETICIONES_DB_PORT', 3306),
    'database' => env('PETICIONES_DB_DATABASE', ''),
    'username' => env('PETICIONES_DB_USERNAME', ''),
    'password' => env('PETICIONES_DB_PASSWORD', ''),
    'charset' => env('PETICIONES_DB_CHARSET', 'utf8mb4'),
    // Opcional: plataformas de streaming (vacío = no consultar TMDb)
    'tmdb_api_key' => env('PETICIONES_TMDB_API_KEY', env('TMDB_API_KEY', '')),
    // Opcional: ScraperAPI si Filmaffinity bloquea el VPS
    'scraper_api_key' => env('PETICIONES_SCRAPER_API_KEY', ''),
];
