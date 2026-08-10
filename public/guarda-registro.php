<?php

declare(strict_types=1);

/**
 * Drop-in alias for SERVEROLD/guarda-registro.php.
 * Nginx routes *.php to this file; rewrite to /registro for the app router.
 */
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/registro');
$_SERVER['REQUEST_URI'] = preg_replace('#/guarda-registro\.php#', '/registro', $uri, 1) ?: '/registro';

require __DIR__ . '/index.php';
