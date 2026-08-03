<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\NotificationTemplateService;
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
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $messages = $this->templates->getExpiryMessages($tenantId);
        $milestones = $this->templates->getMilestones($tenantId);

        return $this->view('settings.notifications', [
            'title' => 'Mensajes Telegram',
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
}
