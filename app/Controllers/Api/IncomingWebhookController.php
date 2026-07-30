<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\EventHub;
use App\Services\WebhookService;
use Core\Controller;
use Core\Database;
use Core\Logger;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Incoming webhooks API — external systems trigger events.
 */
class IncomingWebhookController extends Controller
{
    public function trigger(Request $request, string $event): Response
    {
        $tenantId = (int) Session::getInstance()->get('api_key_tenant_id', 1);
        $payload = $request->json() ?? $request->all();

        if (!in_array($event, WebhookService::EVENTS, true) && $event !== 'custom') {
            return $this->json(['error' => 'Evento no soportado'], 400);
        }

        EventHub::push('incoming.' . $event, $payload);

        $entity_id = is_numeric($event) ? (int) $event : 0;

        Database::getInstance()->insert('audit_logs', [
            'tenant_id' => $tenantId,
            'action' => 'webhook.incoming',
            'entity_type' => 'webhook',
            'entity_id' => $entity_id,
            'new_values' => json_encode($payload),
            'ip_address' => $request->ip(),
        ]);

        Logger::info('Incoming webhook', ['event' => $event, 'tenant' => $tenantId]);

        event('webhook.incoming', ['event' => $event, 'payload' => $payload]);

        return $this->json(['received' => true, 'event' => $event]);
    }

    public function status(Request $request): Response
    {
        $tenantId = (int) Session::getInstance()->get('api_key_tenant_id', 1);

        return $this->json([
            'status' => 'ok',
            'tenant_id' => $tenantId,
            'events' => WebhookService::EVENTS,
        ]);
    }
}
