<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\BillingSettingsService;
use App\Services\CronService;
use App\Services\TwoFactorService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Application settings controller.
 */
class SettingsController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private TwoFactorService $twoFactor = new TwoFactorService(),
        private BillingSettingsService $billingSettings = new BillingSettingsService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $settings = $this->loadSettings($tenantId);

        $stripeSecretKey = $this->billingSettings->getStripeSecretKey($tenantId);

        $appUrl = rtrim((string) config('app.url', ''), '/');
        $cronToken = trim((string) ($settings['cron_token'] ?? env('CRON_TOKEN', '')));
        $cronBase = ($appUrl !== '' ? $appUrl : '') . '/cron/run';
        $appUrlLooksLocal = $appUrl === ''
            || str_contains($appUrl, 'localhost')
            || str_contains($appUrl, '127.0.0.1');
        $stripeMode = str_starts_with($stripeSecretKey, 'sk_live_') || str_starts_with($stripeSecretKey, 'rk_live_')
            ? 'live'
            : (str_starts_with($stripeSecretKey, 'sk_test_') || str_starts_with($stripeSecretKey, 'rk_test_') ? 'test' : null);

        return $this->view('settings.index', [
            'title' => 'Configuración',
            'settings' => $settings,
            'user' => $this->auth->user(),
            'paymentConcept' => $this->billingSettings->getPaymentConcept($tenantId),
            'renewalPresets' => $this->billingSettings->getRenewalPresets($tenantId),
            'stripeSecretKeyMasked' => $this->maskKey($stripeSecretKey),
            'stripeHasSecretKey' => trim($stripeSecretKey) !== '',
            'stripePublishableKey' => $this->billingSettings->getStripePublishableKey($tenantId),
            'stripeHasWebhookSecret' => trim($this->billingSettings->getStripeWebhookSecret($tenantId)) !== '',
            'stripeMode' => $stripeMode,
            'appUrl' => $appUrl,
            'appUrlLooksLocal' => $appUrlLooksLocal,
            'cronCatalog' => CronService::catalog(),
            'cronCliBase' => base_path('cron/run.php'),
            'cronHttpBase' => $cronBase,
            'cronTokenConfigured' => $cronToken !== '',
            'cronTokenMasked' => $cronToken !== '' ? $this->maskKey($cronToken) : '',
        ]);
    }

    public function updateBilling(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        $this->billingSettings->savePaymentConcept($tenantId, (string) $request->input('payment_concept', 'Digital services'));

        $labels = (array) $request->input('preset_label', []);
        $days = (array) $request->input('preset_days', []);
        $prices = (array) $request->input('preset_price', []);

        $presets = [];
        foreach ($labels as $i => $label) {
            $presets[] = [
                'label' => $label,
                'days' => $days[$i] ?? 0,
                'price' => $prices[$i] ?? 0,
            ];
        }

        $this->billingSettings->saveRenewalPresets($tenantId, $presets);

        $stripeErrors = $this->billingSettings->saveStripeKeys(
            $tenantId,
            $request->input('stripe_secret_key') ? (string) $request->input('stripe_secret_key') : null,
            $request->input('stripe_publishable_key') ? (string) $request->input('stripe_publishable_key') : null,
            $request->input('stripe_webhook_secret') ? (string) $request->input('stripe_webhook_secret') : null,
        );

        if ($stripeErrors !== []) {
            Session::getInstance()->flash('error', implode(' ', $stripeErrors));
            return $this->redirect('/settings#billing');
        }

        Session::getInstance()->flash('success', 'Configuración de facturación guardada.');
        return $this->redirect('/settings#billing');
    }

    private function maskKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }

        $tail = substr($key, -4);
        return str_repeat('•', 10) . $tail;
    }

    public function update(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $group = $request->input('group', 'general');

        $fields = match ($group) {
            'smtp' => ['mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_from'],
            'telegram' => [
                'telegram_bot_token',
                'telegram_chat_id',
                'telegram_sandbox_enabled',
                'telegram_sandbox_chat_id',
                'telegram_sandbox_copy_real',
            ],
            'cron' => ['cron_token'],
            'discord' => ['discord_webhook_url'],
            'security' => ['rate_limit_max', 'session_lifetime'],
            default => ['app_name', 'app_timezone', 'app_locale'],
        };

        if ($group === 'telegram') {
            // Checkboxes: si no vienen, guardar 0
            $this->saveSetting($tenantId, 'telegram', 'telegram_sandbox_enabled', $request->input('telegram_sandbox_enabled') ? '1' : '0');
            $this->saveSetting($tenantId, 'telegram', 'telegram_sandbox_copy_real', $request->input('telegram_sandbox_copy_real') ? '1' : '0');
        }

        foreach ($fields as $field) {
            if (in_array($field, ['telegram_sandbox_enabled', 'telegram_sandbox_copy_real'], true)) {
                continue;
            }
            $value = $request->input($field);
            if ($value !== null && $value !== '') {
                $this->saveSetting($tenantId, $group, $field, (string) $value);
            }
        }

        $hash = match ($group) {
            'telegram' => '#telegram',
            'cron' => '#cron',
            'smtp' => '#smtp',
            default => '',
        };

        Session::getInstance()->flash('success', 'Configuración guardada.');
        return $this->redirect('/settings' . $hash);
    }

    public function enable2fa(Request $request): Response
    {
        $user = $this->auth->user();
        $secret = $this->twoFactor->generateSecret();
        $recovery = $this->twoFactor->generateRecoveryCodes();

        $user->two_factor_secret = $secret;
        $user->two_factor_recovery = json_encode($recovery);
        $user->save();

        return $this->json([
            'secret' => $secret,
            'qr_url' => $this->twoFactor->getQrCodeUrl($secret, $user->email),
            'recovery_codes' => $recovery,
        ]);
    }

    public function confirm2fa(Request $request): Response
    {
        $user = $this->auth->user();
        $code = $request->input('code', '');

        if (!$user->two_factor_secret || !$this->twoFactor->verifyCode($user->two_factor_secret, $code)) {
            return $this->json(['error' => 'Código inválido'], 422);
        }

        $user->two_factor_enabled = 1;
        $user->save();

        return $this->json(['success' => true, 'message' => '2FA activado correctamente.']);
    }

    /** @return array<string, string> */
    private function loadSettings(int $tenantId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT `group`, `key`, `value` FROM settings WHERE tenant_id = ? OR tenant_id IS NULL',
            [$tenantId]
        );

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        return $settings;
    }

    private function saveSetting(int $tenantId, string $group, string $key, string $value): void
    {
        $db = Database::getInstance();
        $existing = $db->fetchOne(
            'SELECT id FROM settings WHERE tenant_id = ? AND `group` = ? AND `key` = ?',
            [$tenantId, $group, $key]
        );

        if ($existing) {
            $db->update('settings', ['value' => $value], 'id = ?', [$existing['id']]);
        } else {
            $db->insert('settings', [
                'tenant_id' => $tenantId,
                'group' => $group,
                'key' => $key,
                'value' => $value,
                'type' => 'string',
            ]);
        }
    }
}
