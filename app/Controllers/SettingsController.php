<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AlertSettingsService;
use App\Services\AuthService;
use App\Services\BillingSettingsService;
use App\Services\CronService;
use App\Services\Payments\StripeGateway;
use App\Services\Peticiones\PeticionesConfig;
use App\Services\Peticiones\PeticionesDatabase;
use App\Services\Notifications\NtfyChannel;
use App\Services\PortalShopService;
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

        $stripe = $this->billingSettings->getStripeUiState($tenantId);
        $stripeMode = $stripe['mode'];
        $activeStripe = $stripeMode === 'live' ? $stripe['live'] : $stripe['test'];

        $appUrl = rtrim((string) config('app.url', ''), '/');
        $cronToken = trim((string) ($settings['cron_token'] ?? env('CRON_TOKEN', '')));
        $cronBase = ($appUrl !== '' ? $appUrl : '') . '/cron/run';
        $appUrlLooksLocal = $appUrl === ''
            || str_contains($appUrl, 'localhost')
            || str_contains($appUrl, '127.0.0.1');

        $peticionesUi = PeticionesConfig::forSettingsUi($tenantId);
        $tgCfg = TelegramConfig::forTenant($tenantId);
        $webhookBase = rtrim($appUrl, '/');
        if ($appUrlLooksLocal && isset($_SERVER['HTTP_HOST'])) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
            $webhookBase = ($https ? 'https://' : 'http://') . (string) $_SERVER['HTTP_HOST'];
        }

        return $this->view('settings.index', [
            'title' => 'Configuración',
            'settings' => $settings,
            'user' => $this->auth->user(),
            'paymentConcept' => $this->billingSettings->getPaymentConcept($tenantId),
            'renewalPresets' => $this->billingSettings->getRenewalPresets($tenantId),
            'shopExtraAccountPrice' => $this->billingSettings->getExtraAccountPrice($tenantId),
            'shopExtraStreamMonth' => $this->billingSettings->getExtraStreamMonthlyPrice($tenantId),
            'stripeMode' => $stripeMode,
            'stripeHasSecretKey' => $stripe['active_configured'],
            'stripeSecretKeyMasked' => (string) $activeStripe['secret_masked'],
            'stripePublishableKey' => (string) $activeStripe['publishable'],
            'stripeHasWebhookSecret' => (bool) $activeStripe['has_webhook'],
            'stripeTest' => $stripe['test'],
            'stripeLive' => $stripe['live'],
            'appUrl' => $appUrl,
            'appUrlLooksLocal' => $appUrlLooksLocal,
            'cronCatalog' => CronService::catalog(),
            'cronCliBase' => base_path('cron/run.php'),
            'cronHttpBase' => $cronBase,
            'cronTokenConfigured' => $cronToken !== '',
            'cronTokenMasked' => $cronToken !== '' ? $this->maskKey($cronToken) : '',
            'peticiones' => $peticionesUi,
            'telegramBotUsername' => (string) ($tgCfg['bot_username'] ?? ''),
            'telegramWebhookUrl' => ($webhookBase !== '' ? $webhookBase : '') . '/webhooks/telegram/' . $tenantId,
            'telegramWebhookReady' => trim((string) ($tgCfg['webhook_secret'] ?? '')) !== '',
            'whatsappCloudWebhookUrl' => ($webhookBase !== '' ? $webhookBase : '') . '/webhooks/whatsapp/' . $tenantId,
        ]);
    }

    /**
     * Prueba la conexión a la BD remota de peticiones (valores guardados o del formulario).
     */
    public function testPeticionesDb(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $cfg = PeticionesConfig::forTenant($tenantId);

        $host = trim((string) ($request->input('peticiones_db_host') ?? ''));
        $port = (int) ($request->input('peticiones_db_port') ?? 0);
        $database = trim((string) ($request->input('peticiones_db_database') ?? ''));
        $username = trim((string) ($request->input('peticiones_db_username') ?? ''));
        $password = trim((string) ($request->input('peticiones_db_password') ?? ''));

        $override = [
            'host' => $host !== '' ? $host : $cfg['host'],
            'port' => $port > 0 ? $port : $cfg['port'],
            'database' => $database !== '' ? $database : $cfg['database'],
            'username' => $username !== '' ? $username : $cfg['username'],
            'password' => $password !== '' ? $password : $cfg['password'],
            'charset' => $cfg['charset'],
        ];

        $result = PeticionesDatabase::testConnection($tenantId, $override);
        Session::getInstance()->flash(
            $result['ok'] ? 'success' : 'error',
            $result['message']
        );

        return $this->redirect('/settings#peticiones');
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

    public function testNtfy(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $alerts = new AlertSettingsService();

        if (!$alerts->ntfyConfigured($tenantId)) {
            Session::getInstance()->flash(
                'error',
                'Activa ntfy, indica el topic y guarda antes de enviar la prueba.'
            );

            return $this->redirect('/settings#whatsapp');
        }

        $topic = $alerts->ntfyTopic($tenantId);
        $sent = (new NtfyChannel($alerts))->send(
            'MultiPanel — prueba',
            'Mensaje de prueba desde Configuración.' . "\n"
            . 'Topic: ' . $topic . "\n"
            . 'Hora: ' . date('Y-m-d H:i:s'),
            ['tenant_id' => $tenantId, 'level' => 'warning', 'event' => 'test']
        );

        Session::getInstance()->flash(
            $sent ? 'success' : 'error',
            $sent
                ? 'Mensaje de prueba enviado a ntfy (topic: ' . $topic . ').'
                : 'No se pudo enviar a ntfy. Revisa servidor, topic y token.'
        );

        return $this->redirect('/settings#whatsapp');
    }

    public function activateTelegramWebhook(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $result = (new PortalMessagingLinkService())->ensureTelegramWebhook($tenantId);
        Session::getInstance()->flash(
            !empty($result['success']) ? 'success' : 'error',
            (string) ($result['message'] ?? 'No se pudo activar.')
        );

        return $this->redirect('/settings#telegram');
    }

    /**
     * Envía un WhatsApp de prueba vía CallMeBot (valores del formulario o los guardados).
     */
    public function testWhatsApp(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $alerts = new AlertSettingsService();

        $phone = preg_replace('/[^\d+]/', '', trim((string) $request->input('whatsapp_phone', ''))) ?? '';
        if ($phone === '') {
            $phone = $alerts->whatsappPhone($tenantId);
        }

        $apikey = trim((string) $request->input('whatsapp_apikey', ''));
        if ($apikey === '') {
            $apikey = $alerts->whatsappApiKey($tenantId);
        }

        if ($phone === '' || $apikey === '') {
            Session::getInstance()->flash(
                'error',
                'Falta teléfono o API key de CallMeBot. Pégalos arriba (aunque aún no hayas guardado) y vuelve a probar.'
            );
            return $this->redirect('/settings#whatsapp');
        }

        // Guardado temporal en memoria vía override: usamos el canal con settings ya persistidos
        // si coinciden; si no, llamada directa a CallMeBot con los valores del formulario.
        $apiUrl = (string) config('alerts.whatsapp_api_url', 'https://api.callmebot.com/whatsapp.php');
        $text = "MultiPanel — prueba WhatsApp admin\n\n"
            . 'Tel: ' . $phone . "\n"
            . 'Hora: ' . date('Y-m-d H:i:s') . "\n"
            . 'Si recibes esto, CallMeBot ya está listo.';

        try {
            $client = new Client(['timeout' => 20, 'http_errors' => false]);
            $response = $client->get($apiUrl, [
                'query' => [
                    'phone' => ltrim($phone, '+'),
                    'text' => $text,
                    'apikey' => $apikey,
                ],
            ]);
            $code = $response->getStatusCode();
            $body = trim((string) $response->getBody());
            $ok = $code >= 200 && $code < 300 && !str_contains(strtolower($body), 'error');

            if ($ok) {
                Session::getInstance()->flash(
                    'success',
                    'WhatsApp de prueba enviado a ' . $phone . '. Revisa tu móvil (a veces tarda unos segundos).'
                );
            } else {
                Session::getInstance()->flash(
                    'error',
                    'CallMeBot no aceptó el envío (HTTP ' . $code . '). '
                    . 'Si aún esperas el apikey (24h), es normal. Respuesta: '
                    . mb_substr($body !== '' ? $body : 'vacía', 0, 160)
                );
            }
        } catch (GuzzleException $e) {
            Session::getInstance()->flash('error', 'Error al contactar CallMeBot: ' . $e->getMessage());
        }

        return $this->redirect('/settings#whatsapp');
    }

    /**
     * Ping a la API de Stripe con la secret key del modo activo (o la pegada en el formulario).
     * No guarda claves ni crea cobros.
     */
    public function testStripe(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $mode = $this->billingSettings->getStripeMode($tenantId);

        $postedTest = trim((string) $request->input('stripe_secret_key_test', ''));
        $postedLive = trim((string) $request->input('stripe_secret_key_live', ''));
        // Compatibilidad con el campo antiguo de una sola clave.
        $postedLegacy = trim((string) $request->input('stripe_secret_key', ''));

        $posted = $mode === 'live'
            ? ($postedLive !== '' ? $postedLive : $postedLegacy)
            : ($postedTest !== '' ? $postedTest : $postedLegacy);

        // Si el usuario pegó la clave del otro modo en el form, úsala (y avisa del modo).
        if ($posted === '' && $postedTest !== '') {
            $posted = $postedTest;
        }
        if ($posted === '' && $postedLive !== '') {
            $posted = $postedLive;
        }

        $secret = $posted !== ''
            ? $posted
            : $this->billingSettings->getStripeSecretKey($tenantId);

        if (trim($secret) === '') {
            Session::getInstance()->flash(
                'error',
                'No hay clave secreta de Stripe para el modo activo (' . $mode . '). Pégala en la sección correspondiente y pulsa Guardar, o rellénala y vuelve a probar.'
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

        $this->billingSettings->saveShopExtraPrices(
            $tenantId,
            (float) $request->input('shop_extra_account_price', PortalShopService::DEFAULT_EXTRA_ACCOUNT),
            (float) $request->input('shop_extra_stream_month', PortalShopService::DEFAULT_EXTRA_STREAM_MONTH)
        );

        $stripeErrors = $this->billingSettings->saveStripeConfig($tenantId, [
            'mode' => (string) $request->input('stripe_mode', 'test'),
            'test_secret' => $request->input('stripe_secret_key_test') ? (string) $request->input('stripe_secret_key_test') : null,
            'test_publishable' => $request->input('stripe_publishable_key_test') ? (string) $request->input('stripe_publishable_key_test') : null,
            'test_webhook' => $request->input('stripe_webhook_secret_test') ? (string) $request->input('stripe_webhook_secret_test') : null,
            'live_secret' => $request->input('stripe_secret_key_live') ? (string) $request->input('stripe_secret_key_live') : null,
            'live_publishable' => $request->input('stripe_publishable_key_live') ? (string) $request->input('stripe_publishable_key_live') : null,
            'live_webhook' => $request->input('stripe_webhook_secret_live') ? (string) $request->input('stripe_webhook_secret_live') : null,
        ]);

        if ($stripeErrors !== []) {
            Session::getInstance()->flash('error', implode(' ', $stripeErrors));
            return $this->redirect('/settings#billing');
        }

        $mode = $this->billingSettings->getStripeMode($tenantId);
        Session::getInstance()->flash(
            'success',
            'Configuración de facturación guardada. Modo Stripe activo: ' . $mode . '.'
        );
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
                'telegram_bot_username',
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
            ],
            // UI group "whatsapp"; se persiste en settings.group = alerts (AlertSettingsService).
            'whatsapp' => [
                'whatsapp_enabled',
                'whatsapp_phone',
                'whatsapp_apikey',
                'ntfy_enabled',
                'ntfy_server',
                'ntfy_topic',
                'ntfy_token',
                'whatsapp_notify_alta',
                'whatsapp_notify_renew',
                'whatsapp_notify_server_down',
                'whatsapp_notify_digest',
                'whatsapp_notify_critical',
                'ntfy_notify_alta',
                'ntfy_notify_renew',
                'ntfy_notify_server_down',
                'ntfy_notify_digest',
                'ntfy_notify_critical',
                'whatsapp_cloud_token',
                'whatsapp_cloud_phone_id',
                'whatsapp_cloud_display_phone',
                'whatsapp_cloud_verify_token',
                'whatsapp_client_alerts',
                'telegram_notify_alta',
                'telegram_notify_renew',
                'telegram_notify_server_down',
                'telegram_notify_digest',
                'telegram_notify_critical',
                'email_notify_server_down',
                'email_notify_critical',
            ],
            'peticiones' => [
                'peticiones_db_host',
                'peticiones_db_port',
                'peticiones_db_database',
                'peticiones_db_username',
                'peticiones_db_password',
                'peticiones_tmdb_api_key',
                'peticiones_scraper_api_key',
            ],
            'discord' => ['discord_webhook_url'],
            'security' => ['rate_limit_max', 'session_lifetime'],
            default => ['app_name', 'app_timezone', 'app_locale'],
        };

        if ($group === 'peticiones') {
            PeticionesConfig::save($tenantId, [
                'peticiones_db_host' => $request->input('peticiones_db_host'),
                'peticiones_db_port' => $request->input('peticiones_db_port'),
                'peticiones_db_database' => $request->input('peticiones_db_database'),
                'peticiones_db_username' => $request->input('peticiones_db_username'),
                'peticiones_db_password' => $request->input('peticiones_db_password'),
                'peticiones_tmdb_api_key' => $request->input('peticiones_tmdb_api_key'),
                'peticiones_scraper_api_key' => $request->input('peticiones_scraper_api_key'),
            ]);
            PeticionesDatabase::reset();
            Session::getInstance()->flash('success', 'Configuración de peticiones guardada.');
            return $this->redirect('/settings#peticiones');
        }

        if ($group === 'telegram') {
            // Checkboxes: si no vienen, guardar 0
            $this->saveSetting($tenantId, 'telegram', 'telegram_sandbox_enabled', $request->input('telegram_sandbox_enabled') ? '1' : '0');
            $this->saveSetting($tenantId, 'telegram', 'telegram_sandbox_copy_real', $request->input('telegram_sandbox_copy_real') ? '1' : '0');
        }

        if ($group === 'whatsapp') {
            $checkboxKeys = [
                'whatsapp_enabled',
                'ntfy_enabled',
                'whatsapp_notify_alta',
                'whatsapp_notify_renew',
                'whatsapp_notify_server_down',
                'whatsapp_notify_digest',
                'whatsapp_notify_critical',
                'ntfy_notify_alta',
                'ntfy_notify_renew',
                'ntfy_notify_server_down',
                'ntfy_notify_digest',
                'ntfy_notify_critical',
                'telegram_notify_alta',
                'telegram_notify_renew',
                'telegram_notify_server_down',
                'telegram_notify_digest',
                'telegram_notify_critical',
                'email_notify_server_down',
                'email_notify_critical',
                'whatsapp_client_alerts',
            ];
            foreach ($checkboxKeys as $checkboxKey) {
                $this->saveSetting(
                    $tenantId,
                    'alerts',
                    $checkboxKey,
                    $request->input($checkboxKey) ? '1' : '0'
                );
            }
        }

        $persistGroup = $group === 'whatsapp' ? 'alerts' : $group;

        $whatsappCheckboxes = [
            'whatsapp_enabled',
            'ntfy_enabled',
            'whatsapp_notify_alta',
            'whatsapp_notify_renew',
            'whatsapp_notify_server_down',
            'whatsapp_notify_digest',
            'whatsapp_notify_critical',
            'ntfy_notify_alta',
            'ntfy_notify_renew',
            'ntfy_notify_server_down',
            'ntfy_notify_digest',
            'ntfy_notify_critical',
            'telegram_notify_alta',
            'telegram_notify_renew',
            'telegram_notify_server_down',
            'telegram_notify_digest',
            'telegram_notify_critical',
            'email_notify_server_down',
            'email_notify_critical',
            'whatsapp_client_alerts',
        ];

        foreach ($fields as $field) {
            $skipCheckboxes = array_merge(
                ['telegram_sandbox_enabled', 'telegram_sandbox_copy_real'],
                $whatsappCheckboxes
            );
            if (in_array($field, $skipCheckboxes, true)) {
                continue;
            }
            // cron_token / secrets: vacío = no cambiar
            if (in_array($field, ['cron_token', 'whatsapp_apikey', 'whatsapp_cloud_token', 'whatsapp_cloud_verify_token', 'ntfy_token'], true)
                && ($request->input($field) === null || $request->input($field) === '')) {
                continue;
            }
            $value = $request->input($field);
            if ($value !== null && $value !== '') {
                $this->saveSetting($tenantId, $persistGroup, $field, (string) $value);
            }
        }

        $hash = match ($group) {
            'telegram' => '#telegram',
            'cron' => '#cron',
            'smtp' => '#smtp',
            'alerts' => '#cron',
            'whatsapp' => '#whatsapp',
            'peticiones' => '#peticiones',
            'billing' => '#billing',
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
