<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\BillingSettingsService;
use App\Services\CronService;
use App\Services\Payments\StripeGateway;
use App\Services\TelegramConfig;
use App\Services\TelegramSandboxSender;
use App\Services\TwoFactorService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

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

    /**
     * Envía un mensaje de prueba al chat sandbox (si está activo) o al chat admin.
     */
    public function testTelegram(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $cfg = TelegramConfig::forTenant($tenantId);
        $botToken = $cfg['bot_token'];

        if ($botToken === '') {
            Session::getInstance()->flash('error', 'Configura el Bot Token de Telegram antes de enviar la prueba.');
            return $this->redirect('/settings#telegram');
        }

        $useSandbox = $cfg['sandbox_enabled'] && $cfg['sandbox_chat_id'] !== '';
        $chatId = $useSandbox ? $cfg['sandbox_chat_id'] : $cfg['admin_chat_id'];
        $destLabel = $useSandbox ? 'sandbox' : 'admin';

        if ($chatId === '') {
            Session::getInstance()->flash(
                'error',
                'No hay destino: activa sandbox con Sandbox Chat ID, o indica el Chat ID del admin.'
            );
            return $this->redirect('/settings#telegram');
        }

        $text = "MultiPanel — mensaje de prueba\n\n"
            . 'Destino: ' . $destLabel . ' (' . $chatId . ")\n"
            . 'Hora: ' . date('Y-m-d H:i:s');

        try {
            $client = new Client(['timeout' => 15]);
            $client->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'json' => [
                    'chat_id' => $chatId,
                    'text' => $text,
                ],
            ]);
            Session::getInstance()->flash(
                'success',
                'Mensaje de prueba enviado a Telegram (' . $destLabel . ': ' . $chatId . '). Revisa tu chat.'
            );
        } catch (GuzzleException $e) {
            Session::getInstance()->flash('error', 'Telegram: ' . TelegramSandboxSender::formatApiError($e));
        }

        return $this->redirect('/settings#telegram');
    }

    /**
     * Ping a la API de Stripe con la secret key guardada (o la pegada en el formulario).
     * No guarda claves ni crea cobros.
     */
    public function testStripe(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $posted = trim((string) $request->input('stripe_secret_key', ''));
        $secret = $posted !== ''
            ? $posted
            : $this->billingSettings->getStripeSecretKey($tenantId);

        if (trim($secret) === '') {
            Session::getInstance()->flash(
                'error',
                'No hay clave secreta de Stripe. Pégala arriba y pulsa Guardar facturación, o rellénala y vuelve a probar.'
            );
            return $this->redirect('/settings#billing');
        }

        $result = (new StripeGateway($secret))->testConnection();
        if (!empty($result['ok'])) {
            Session::getInstance()->flash('success', (string) $result['message']);
        } else {
            Session::getInstance()->flash('error', (string) ($result['message'] ?? 'No se pudo conectar con Stripe.'));
        }

        return $this->redirect('/settings#billing');
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
            'cron' => [
                'cron_token',
                'expiry_notify_hour',
                'expiry_notify_timezone',
                'expiry_notify_window_minutes',
            ],
            'alerts' => [
                'alert_email',
                'whatsapp_enabled',
                'whatsapp_phone',
                'whatsapp_apikey',
            ],
            'discord' => ['discord_webhook_url'],
            'security' => ['rate_limit_max', 'session_lifetime'],
            default => ['app_name', 'app_timezone', 'app_locale'],
        };

        if ($group === 'telegram') {
            // Checkboxes: si no vienen, guardar 0
            $this->saveSetting($tenantId, 'telegram', 'telegram_sandbox_enabled', $request->input('telegram_sandbox_enabled') ? '1' : '0');
            $this->saveSetting($tenantId, 'telegram', 'telegram_sandbox_copy_real', $request->input('telegram_sandbox_copy_real') ? '1' : '0');
        }

        if ($group === 'alerts') {
            $this->saveSetting($tenantId, 'alerts', 'whatsapp_enabled', $request->input('whatsapp_enabled') ? '1' : '0');
        }

        foreach ($fields as $field) {
            if (in_array($field, ['telegram_sandbox_enabled', 'telegram_sandbox_copy_real', 'whatsapp_enabled'], true)) {
                continue;
            }
            // cron_token / whatsapp_apikey: vacío = no cambiar
            if (in_array($field, ['cron_token', 'whatsapp_apikey'], true) && ($request->input($field) === null || $request->input($field) === '')) {
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
            'alerts' => '#cron',
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
