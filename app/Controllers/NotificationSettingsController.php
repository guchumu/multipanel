<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\NotificationTemplateService;
use App\Services\TelegramSandboxSender;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Edit Telegram expiry notification templates per tenant.
 */
class NotificationSettingsController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private NotificationTemplateService $templates = new NotificationTemplateService(),
        private TelegramSandboxSender $sandboxSender = new TelegramSandboxSender(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $messages = $this->templates->getExpiryMessages($tenantId);
        $milestones = $this->templates->getMilestones($tenantId);

        return $this->view('settings.notifications', [
            'title' => 'Mensajes a los usuarios',
            'messages' => $messages,
            'milestones' => $milestones,
            'placeholders' => '{username}, {email}, {display_name}, {expires_at}, {end_date}, {days_left}, {server_name}',
        ]);
    }

    public function update(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $messages = [];

        foreach ($request->all() as $key => $value) {
            if (str_starts_with((string) $key, 'message_') && is_string($value)) {
                $milestone = substr((string) $key, 8);
                $messages[$milestone] = $value;
            }
        }

        $this->templates->saveExpiryMessages($tenantId, $messages);
        Session::getInstance()->flash('success', 'Plantillas de aviso guardadas.');

        return $this->redirect('/settings/notifications');
    }

    /**
     * Envía la plantilla de un milestone al Sandbox Chat ID (siempre sandbox).
     */
    public function test(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $milestoneRaw = trim((string) $request->input('milestone', ''));

        if ($milestoneRaw === '' || !preg_match('/^-?\d+$/', $milestoneRaw)) {
            Session::getInstance()->flash('error', 'Milestone de aviso no válido.');
            return $this->redirect('/settings/notifications');
        }

        $daysLeft = (int) $milestoneRaw;
        $messages = $this->templates->getExpiryMessages($tenantId);
        $template = $messages[$daysLeft] ?? $messages[$milestoneRaw] ?? null;

        if (!is_string($template) || trim($template) === '') {
            Session::getInstance()->flash(
                'error',
                'No hay plantilla guardada para este aviso. Guarda el texto antes de probar.'
            );
            return $this->redirect('/settings/notifications');
        }

        $title = (string) config('expiry_notifications.title', 'Aviso de tu acceso');
        $body = TelegramSandboxSender::renderWithSamples($template, $daysLeft);
        $label = $daysLeft === -1
            ? 'caducó ayer (-1)'
            : ($daysLeft === 0 ? 'caduca hoy (0)' : "faltan {$daysLeft} días");

        $text = "*{$title}*\n\n{$body}\n\n_[PRUEBA plantilla · {$label}]_";
        $result = $this->sandboxSender->sendToSandbox($tenantId, $text, 'Markdown');

        Session::getInstance()->flash($result['ok'] ? 'success' : 'error', $result['message']);

        return $this->redirect('/settings/notifications');
    }
}
