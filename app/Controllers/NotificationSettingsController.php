<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\NotificationTemplateService;
use App\Services\ReengageCampaignService;
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
        private ReengageCampaignService $reengage = new ReengageCampaignService(),
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
            'placeholders' => '{username}, {email}, {display_name}, {expires_at}, {end_date}, {days_left}, {server_name}, {year_price}',
            'reengage' => $this->reengage->getConfig($tenantId),
            'reengagePlaceholders' => '{username}, {email}, {display_name}, {end_date}, {server_name}, {service_name}, {trial_days}, {discount_percent}, {link_years}, {portal_url}',
            'reengageStats' => $this->reengage->stats($tenantId),
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
            : ($daysLeft < 0
                ? ('caducó hace ' . abs($daysLeft) . ' días')
                : ($daysLeft === 0 ? 'caduca hoy (0)' : "faltan {$daysLeft} días"));

        $text = "*{$title}*\n\n{$body}\n\n_[PRUEBA plantilla · {$label}]_";
        $result = $this->sandboxSender->sendToSandbox($tenantId, $text, 'Markdown');

        Session::getInstance()->flash($result['ok'] ? 'success' : 'error', $result['message']);

        return $this->redirect('/settings/notifications');
    }

    public function updateReengage(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $this->reengage->saveConfig($tenantId, [
            'enabled' => $request->input('enabled') ? true : false,
            'interval_days' => $request->input('interval_days'),
            'max_sends' => $request->input('max_sends'),
            'min_expired_days' => $request->input('min_expired_days'),
            'trial_days' => $request->input('trial_days'),
            'discount_percent' => $request->input('discount_percent'),
            'link_ttl_days' => $request->input('link_ttl_days'),
            'invite_title_1' => $request->input('invite_title_1'),
            'invite_body_1' => $request->input('invite_body_1'),
            'invite_title_2' => $request->input('invite_title_2'),
            'invite_body_2' => $request->input('invite_body_2'),
            'invite_title_3' => $request->input('invite_title_3'),
            'invite_body_3' => $request->input('invite_body_3'),
            'invite_title_4' => $request->input('invite_title_4'),
            'invite_body_4' => $request->input('invite_body_4'),
            'trial_title' => $request->input('trial_title'),
            'trial_body' => $request->input('trial_body'),
        ]);
        Session::getInstance()->flash('success', 'Campaña de reenganche guardada.');

        return $this->redirect('/settings/notifications#reengage');
    }

    public function testReengage(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $kind = (string) $request->input('kind', 'invite') === 'trial' ? 'trial' : 'invite';
        $step = max(1, min(4, (int) $request->input('step', 1)));
        $cfg = $this->reengage->getConfig($tenantId);
        $tpl = $this->reengage->templateFor($cfg, $kind, $step);
        if ($tpl === null) {
            Session::getInstance()->flash('error', 'Guarda el texto antes de probar.');
            return $this->redirect('/settings/notifications#reengage');
        }

        $sample = new \App\Models\MediaUser([
            'username' => 'demo',
            'display_name' => 'Ana',
            'email' => 'ana@ejemplo.com',
            'expires_at' => date('Y-m-d', strtotime('+3 days')),
        ]);
        $demoUrl = rtrim((string) config('app.url', ''), '/') . '/u/EjemploEnlace1AnoPlexDemo';
        $body = $this->reengage->render($tpl['body'], $sample, $cfg, 'Server10', $demoUrl);
        $head = $this->reengage->render($tpl['title'], $sample, $cfg, 'Server10', $demoUrl);
        $label = $kind === 'trial' ? 'prueba abierta' : ('aviso ' . $tpl['step'] . '/4');
        $text = "*{$head}*\n\n{$body}\n\n_[PRUEBA reenganche · {$label}]_";
        $result = $this->sandboxSender->sendToSandbox($tenantId, $text, 'Markdown');
        Session::getInstance()->flash($result['ok'] ? 'success' : 'error', $result['message']);

        return $this->redirect('/settings/notifications#reengage');
    }
}
