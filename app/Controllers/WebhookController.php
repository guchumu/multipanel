<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\WebhookService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Outgoing webhook management controller.
 */
class WebhookController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private WebhookService $webhooks = new WebhookService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        $deliveries = [];
        try {
            $deliveries = Database::getInstance()->fetchAll(
                'SELECT d.*, e.name as endpoint_name FROM webhook_deliveries d
                 JOIN webhook_endpoints e ON e.id = d.endpoint_id
                 WHERE e.tenant_id = ? ORDER BY d.created_at DESC LIMIT 20',
                [$tenantId]
            );
        } catch (\Throwable) {
            // tables may not exist yet
        }

        return $this->view('webhooks.index', [
            'title' => 'Webhooks',
            'endpoints' => $this->webhooks->listEndpoints($tenantId),
            'deliveries' => $deliveries,
            'events' => WebhookService::EVENTS,
        ]);
    }

    public function store(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $data = $this->validate($request, [
            'name' => 'required|min:2',
            'url' => 'required|min:10',
        ]);

        $events = $request->input('events', []);
        if (!is_array($events) || empty($events)) {
            $events = ['*'];
        }

        $this->webhooks->createEndpoint($tenantId, [
            'name' => $data['name'],
            'url' => $data['url'],
            'secret' => $request->input('secret'),
            'events' => $events,
        ]);

        Session::getInstance()->flash('success', 'Webhook creado.');
        return $this->redirect('/webhooks');
    }

    public function test(Request $request, int $id): Response
    {
        $ok = $this->webhooks->testEndpoint($id);
        Session::getInstance()->flash($ok ? 'success' : 'error', $ok ? 'Webhook de prueba enviado.' : 'Error al enviar webhook.');
        return $this->redirect('/webhooks');
    }

    public function destroy(Request $request, int $id): Response
    {
        $this->webhooks->deleteEndpoint($id);
        Session::getInstance()->flash('success', 'Webhook eliminado.');
        return $this->redirect('/webhooks');
    }
}
