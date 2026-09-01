#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Deduplica playback_sessions infladas por sync/streams repetidos.
 *
 * Uso:
 *   php scripts/dedupe-playback-sessions.php --dry-run
 *   php scripts/dedupe-playback-sessions.php --apply
 *   php scripts/dedupe-playback-sessions.php --apply --tenant=1 --since=2026-08-29
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/core/helpers.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$apply = in_array('--apply', $argv, true);
$dryRun = in_array('--dry-run', $argv, true) || !$apply;

if ($apply && $dryRun && in_array('--dry-run', $argv, true)) {
    fwrite(STDERR, "Usa solo --dry-run o --apply, no ambos.\n");
    exit(1);
}

$tenantId = 0;
$since = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, 9);
    }
    if (str_starts_with($arg, '--since=')) {
        $since = substr($arg, 8);
    }
}

try {
    $result = (new App\Services\PlaybackSessionDedupeService())->run($tenantId, $since, $apply);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

$mode = $result['apply'] ? 'APLICADO' : 'SIMULACIÓN (sin cambios)';

echo "=== Dedupe playback_sessions [{$mode}] ===\n";
echo 'Tenants:   ' . (int) $result['tenants'] . "\n";
echo 'Filas leídas: ' . (int) $result['scanned'] . "\n";
echo 'Grupos duplicados: ' . (int) $result['clusters'] . "\n";
echo 'Grupos fusionados: ' . (int) $result['merged'] . "\n";
echo 'Filas a eliminar / eliminadas: ' . (int) $result['deleted'] . "\n";
if ($result['since'] !== null) {
    echo 'Desde:     ' . $result['since'] . "\n";
}
if (!$result['apply']) {
    echo "\nPara aplicar: php scripts/dedupe-playback-sessions.php --apply";
    if ($tenantId > 0) {
        echo " --tenant={$tenantId}";
    }
    if ($since !== null) {
        echo " --since={$since}";
    }
    echo "\n";
}

exit(0);
