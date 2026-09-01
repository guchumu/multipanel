<?php

declare(strict_types=1);

/**
 * TEMPORAL — Ejecutar dedupe de playback_sessions y BORRAR este archivo.
 *
 * URL: https://tu-dominio/dedupe-playback-once.php?key=VALOR_DE_APP_KEY_EN_.env
 *      &apply=1  (opcional, para aplicar; sin apply = solo simulación)
 *      &since=2026-08-29  (opcional)
 *      &tenant=1  (opcional)
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
require_once dirname(__DIR__) . '/core/helpers.php';

header('Content-Type: text/html; charset=utf-8');

$appKey = (string) env('APP_KEY', '');
$providedKey = (string) ($_GET['key'] ?? '');

if ($appKey === '' || !hash_equals($appKey, $providedKey)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem">';
    echo '<h1>403</h1><p>Acceso denegado.</p>';
    echo '<p>Abre esta URL con <code>?key=</code> y el valor de <code>APP_KEY</code> de tu archivo <code>.env</code>.</p>';
    echo '<p><small>Ejemplo: <code>/dedupe-playback-once.php?key=tu_app_key_aqui</code></small></p>';
    echo '</body></html>';
    exit;
}

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';
$tenantId = max(0, (int) ($_GET['tenant'] ?? 0));
$since = trim((string) ($_GET['since'] ?? '2026-08-29'));
$sinceParam = $since !== '' ? $since : null;

$baseQuery = 'key=' . rawurlencode($providedKey);
if ($sinceParam !== null) {
    $baseQuery .= '&since=' . rawurlencode($sinceParam);
}
if ($tenantId > 0) {
    $baseQuery .= '&tenant=' . $tenantId;
}

try {
    $result = (new App\Services\PlaybackSessionDedupeService())->run($tenantId, $sinceParam, $apply);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

$mode = $apply ? 'APLICADO' : 'SIMULACIÓN';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dedupe playback_sessions</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        .warn { background: #fff3cd; border: 1px solid #ffc107; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; }
        table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
        th, td { border: 1px solid #ddd; padding: 0.5rem 0.75rem; text-align: left; }
        th { background: #f4f4f4; }
        .btn { display: inline-block; margin: 0.25rem; padding: 0.6rem 1rem; border-radius: 6px; text-decoration: none; font-weight: 600; }
        .btn-safe { background: #0d6efd; color: #fff; }
        .btn-danger { background: #dc3545; color: #fff; }
        code { background: #f4f4f4; padding: 0.1rem 0.35rem; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="warn">
        <strong>Archivo temporal.</strong> Borra <code>public/dedupe-playback-once.php</code> del servidor cuando termines.
    </div>

    <h1>Dedupe playback_sessions</h1>
    <p>Modo: <strong><?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?></strong></p>

    <table>
        <tr><th>Tenants</th><td><?= (int) $result['tenants'] ?></td></tr>
        <tr><th>Filas leídas</th><td><?= (int) $result['scanned'] ?></td></tr>
        <tr><th>Grupos duplicados</th><td><?= (int) $result['clusters'] ?></td></tr>
        <tr><th>Grupos fusionados</th><td><?= (int) $result['merged'] ?></td></tr>
        <tr><th>Filas eliminadas / a eliminar</th><td><?= (int) $result['deleted'] ?></td></tr>
        <?php if ($result['since'] !== null): ?>
        <tr><th>Desde</th><td><?= htmlspecialchars((string) $result['since'], ENT_QUOTES, 'UTF-8') ?></td></tr>
        <?php endif; ?>
    </table>

    <p>
        <a class="btn btn-safe" href="?<?= htmlspecialchars($baseQuery, ENT_QUOTES, 'UTF-8') ?>">Simular otra vez</a>
        <?php if (!$apply): ?>
        <a class="btn btn-danger" href="?<?= htmlspecialchars($baseQuery . '&apply=1', ENT_QUOTES, 'UTF-8') ?>"
           onclick="return confirm('¿Fusionar duplicados y borrar filas sobrantes?');">Aplicar limpieza</a>
        <?php endif; ?>
    </p>

    <p><small>Parámetros: <code>since</code> (fecha), <code>tenant</code> (id), <code>apply=1</code> (aplicar).</small></p>
</body>
</html>
