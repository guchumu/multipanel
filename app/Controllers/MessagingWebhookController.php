<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AlertSettingsService;
use App\Services\PortalMessagingLinkService;
use Core\Controller;
use Core\Logger;
use Core\Request;
use Core\Response;

/**
 * Webhooks públicos: vinculación Telegram y WhatsApp Cloud (Meta).
 */
final class MessagingWebhookController extends Controller
{
    public function __construct(
        private PortalMessagingLinkService $links = new PortalMessagingLinkService(),
        private AlertSettingsService $alerts = new AlertSettingsService(),
    ) {
    }

    public function telegram(Request $request, string $tenantId = '1'): Response
    {
        $tid = max(1, (int) $tenantId);
        $secret = $request->header('X-Telegram-Bot-Api-Secret-Token');
        if (!$this->links->verifyTelegramSecret($tid, $secret)) {
            return $this->json(['ok' => false], 403);
        }

        $payload = $request->json();
        if (!is_array($payload)) {
            $decoded = json_decode($request->rawBody(), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        try {
            $this->links->handleTelegramUpdate($tid, $payload);
        } catch (\Throwable $e) {
            Logger::error('Telegram webhook failed', ['error' => $e->getMessage(), 'tenant_id' => $tid]);
        }

        return $this->json(['ok' => true]);
    }

    public function whatsapp(Request $request, string $tenantId = '1'): Response
    {
        $tid = max(1, (int) $tenantId);

        if (strtoupper($request->method()) === 'GET') {
            $mode = (string) $request->input('hub_mode', $request->input('hub.mode', ''));
            $token = (string) $request->input('hub_verify_token', $request->input('hub.verify_token', ''));
            $challenge = (string) $request->input('hub_challenge', $request->input('hub.challenge', ''));
            $expected = $this->alerts->whatsappCloudVerifyToken($tid);
            if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
                return new Response($challenge, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
            }

            return new Response('forbidden', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $payload = $request->json();
        if (!is_array($payload)) {
            $decoded = json_decode($request->rawBody(), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        try {
            $this->links->handleWhatsAppCloudPayload($tid, $payload);
        } catch (\Throwable $e) {
            Logger::error('WhatsApp webhook failed', ['error' => $e->getMessage(), 'tenant_id' => $tid]);
        }

        return $this->json(['ok' => true]);
    }
}
