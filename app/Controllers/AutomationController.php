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

    public function toggle(Request $request, int $id): Response
    {
        $rule = Database::getInstance()->fetchOne('SELECT * FROM automation_rules WHERE id = ?', [$id]);
        if (!$rule) {
            return $this->json(['error' => 'Regla no encontrada'], 404);
        }

        $newStatus = $rule['is_active'] ? 0 : 1;
        Database::getInstance()->update('automation_rules', ['is_active' => $newStatus], 'id = ?', [$id]);

        return $this->json(['success' => true, 'is_active' => (bool) $newStatus]);
    }

    public function destroy(Request $request, int $id): Response
    {
        Database::getInstance()->delete('automation_rules', 'id = ?', [$id]);
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
