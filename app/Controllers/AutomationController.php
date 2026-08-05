<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\AutomationEngine;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Automation rules management controller.
 */
class AutomationController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private AutomationEngine $engine = new AutomationEngine(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $rules = Database::getInstance()->fetchAll(
            'SELECT * FROM automation_rules WHERE tenant_id = ? ORDER BY priority DESC, name',
            [$tenantId]
        );

        return $this->view('automation.index', [
            'title' => 'Automatizaciones',
            'rules' => $rules,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('automation.create', ['title' => 'Nueva regla']);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name' => 'required|max:255',
            'trigger_event' => 'required|max:100',
        ]);

        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        Database::getInstance()->insert('automation_rules', [
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'description' => $request->input('description'),
            'trigger_event' => $data['trigger_event'],
            'conditions' => json_encode([
                ['field' => 'trigger', 'operator' => 'equals', 'value' => $data['trigger_event']],
            ]),
            'actions' => json_encode([
                self::buildAction($request),
            ]),
            'priority' => (int) ($request->input('priority') ?? 0),
            'is_active' => $request->input('is_active') ? 1 : 0,
        ]);

        Session::getInstance()->flash('success', 'Regla creada correctamente.');
        return $this->redirect('/automation');
    }

    public function run(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $count = $this->engine->runAll($tenantId);

        return $this->json([
            'success' => true,
            'executed' => $count,
            'message' => "Se ejecutaron {$count} reglas.",
        ]);
    }

    public function toggle(Request $request, int|string $id): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $ruleId = (int) $id;

        $rule = Database::getInstance()->fetchOne(
            'SELECT * FROM automation_rules WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$ruleId, $tenantId]
        );
        if (!$rule) {
            return $this->json(['success' => false, 'error' => 'Regla no encontrada'], 404);
        }

        // Cast explícito: PDO puede devolver "0"/"1" como string.
        $currentlyActive = (int) $rule['is_active'] === 1;
        $newStatus = $currentlyActive ? 0 : 1;

        $updated = Database::getInstance()->update(
            'automation_rules',
            ['is_active' => $newStatus],
            'id = ? AND tenant_id = ?',
            [$ruleId, $tenantId]
        );

        if ($updated < 1 && (int) $rule['is_active'] === $newStatus) {
            // Ya estaba en ese estado (carrera rara): OK.
        }

        $fresh = Database::getInstance()->fetchOne(
            'SELECT is_active FROM automation_rules WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$ruleId, $tenantId]
        );

        return $this->json([
            'success' => true,
            'is_active' => (int) ($fresh['is_active'] ?? $newStatus) === 1,
            'message' => ((int) ($fresh['is_active'] ?? $newStatus) === 1) ? 'Regla activada.' : 'Regla desactivada.',
        ]);
    }

    public function destroy(Request $request, int|string $id): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        Database::getInstance()->delete('automation_rules', 'id = ? AND tenant_id = ?', [(int) $id, $tenantId]);
        Session::getInstance()->flash('success', 'Regla eliminada.');
        return $this->redirect('/automation');
    }

    private static function buildAction(Request $request): array
    {
        $type = $request->input('action_type', 'notify');

        return match ($type) {
            'suspend_user' => ['type' => 'suspend_user', 'params' => ['days_overdue' => 5]],
            'delete_user' => ['type' => 'delete_user', 'params' => ['days_overdue' => 15]],
            'activate_user' => ['type' => 'activate_user', 'params' => []],
            default => ['type' => 'notify', 'params' => [
                'title' => $request->input('action_title', 'Automatización'),
                'message' => $request->input('action_message', ''),
                'channels' => ['telegram'],
            ]],
        };
    }
}
