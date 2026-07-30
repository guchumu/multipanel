<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;

/**
 * Audit logs viewer controller.
 */
class LogController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $action = $request->input('action');
        $params = [$tenantId];
        $where = 'al.tenant_id = ?';

        if ($action) {
            $where .= ' AND al.action LIKE ?';
            $params[] = "%{$action}%";
        }

        $countWhere = str_replace('al.', '', $where);

        $logs = Database::getInstance()->fetchAll(
            "SELECT al.*, u.username, u.email
             FROM audit_logs al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE {$where}
             ORDER BY al.created_at DESC
             LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );

        $total = Database::getInstance()->fetchOne(
            "SELECT COUNT(*) as total FROM audit_logs al WHERE {$countWhere}",
            $params
        );

        return $this->view('logs.index', [
            'title' => 'Logs de auditoría',
            'logs' => $logs,
            'page' => $page,
            'total' => (int) ($total['total'] ?? 0),
            'perPage' => $perPage,
            'currentAction' => $action,
        ]);
    }

    public function export(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        $logs = Database::getInstance()->fetchAll(
            'SELECT action, entity_type, entity_id, ip_address, created_at FROM audit_logs WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 5000',
            [$tenantId]
        );

        $export = new \App\Services\ExportService();
        $path = $export->toCsv($logs, ['action', 'entity_type', 'entity_id', 'ip_address', 'created_at'], 'audit_logs_' . date('Y-m-d') . '.csv');
        $export->downloadResponse($path);
    }
}
