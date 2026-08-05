<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CronService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;

/**
 * Endpoint HTTP para cron (Plesk/crontab web) protegido por token.
 */
class CronController extends Controller
{
    public function run(Request $request, ?string $task = null): Response
    {
        $token = (string) ($request->input('token')
            ?? $request->header('X-Cron-Token')
            ?? '');
        $expected = $this->expectedToken();

        if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
            return $this->json([
                'ok' => false,
                'error' => 'Token cron inválido o no configurado. Define CRON_TOKEN en .env o en Configuración → Cron.',
            ], 403);
        }

        $taskName = trim((string) ($task ?? $request->input('task') ?? 'all'));
        $tenantId = max(1, (int) $request->input('tenant_id', 1));
        $result = (new CronService())->run($taskName, $tenantId);

        return $this->json([
            'ok' => $result['ok'],
            'task' => $result['task'],
            'lines' => $result['lines'],
        ], $result['ok'] ? 200 : 400);
    }

    private function expectedToken(): string
    {
        $fromEnv = trim((string) env('CRON_TOKEN', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        try {
            $row = Database::getInstance()->fetchOne(
                "SELECT value FROM settings WHERE `group` = 'cron' AND `key` = 'cron_token' ORDER BY tenant_id IS NULL, tenant_id LIMIT 1"
            );
            return trim((string) ($row['value'] ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }
}
