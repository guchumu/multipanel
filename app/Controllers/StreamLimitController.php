<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ConcurrentStreamLimitService;
use App\Services\StreamLimitSettingsService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Settings + violation log for concurrent stream limits.
 */
class StreamLimitController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private StreamLimitSettingsService $settings = new StreamLimitSettingsService(),
        private ConcurrentStreamLimitService $enforcer = new ConcurrentStreamLimitService(),
    ) {
    }

    public function settings(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        return $this->view('settings.stream_limits', [
            'title' => 'Límite de streams',
            'settings' => $this->settings->all($tenantId),
            'effectiveKillMessage' => $this->settings->getKillMessage($tenantId),
        ]);
    }

    public function updateSettings(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        $enabled = (bool) $request->input('enforcement_enabled');
        $defaultMax = max(1, min(50, (int) $request->input('default_max_streams', 2)));
        $killMessage = trim((string) $request->input('kill_message', ''));
        $countMode = trim((string) $request->input('count_mode', 'distinct_ip'));

        $this->settings->setEnforcementEnabled($tenantId, $enabled);
        $this->settings->setDefaultMaxStreams($tenantId, $defaultMax);
        $this->settings->setKillMessage($tenantId, $killMessage !== '' ? $killMessage : null);
        $this->settings->setCountMode($tenantId, $countMode);

        Session::getInstance()->flash('success', 'Ajustes de límite de streams guardados.');

        return $this->redirect('/settings/stream-limits');
    }

    public function violations(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $limit = max(20, min(300, (int) $request->input('limit', 100)));

        return $this->view('media_users.stream_violations', [
            'title' => 'Incumplimientos de streams',
            'violations' => $this->enforcer->listViolations($tenantId, $limit),
            'enforcementEnabled' => $this->settings->isEnforcementEnabled($tenantId),
            'defaultMaxStreams' => $this->settings->getDefaultMaxStreams($tenantId),
        ]);
    }
}
