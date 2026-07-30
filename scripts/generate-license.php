#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate MultiPanel license keys from CLI.
 *
 * Usage: php scripts/generate-license.php [plan] [days] [domain]
 * Example: php scripts/generate-license.php enterprise 365 example.com
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/core/helpers.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$plan = $argv[1] ?? 'enterprise';
$days = (int) ($argv[2] ?? 365);
$domain = $argv[3] ?? '*';

$expiresAt = date('Y-m-d', strtotime("+{$days} days"));

$service = new App\Services\LicenseService();
$key = $service->generateKey($plan, $expiresAt, $domain);

echo "MultiPanel License Key Generator\n";
echo "================================\n";
echo "Plan:     {$plan}\n";
echo "Expires:  {$expiresAt}\n";
echo "Domain:   {$domain}\n";
echo "Key:\n{$key}\n";
