<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Services\Notifications\ClientWhatsAppChannel;
use Core\Database;
use Core\Logger;
use Core\Updater;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Vinculación de Telegram / WhatsApp desde el portal (códigos de un uso).
 */
final class PortalMessagingLinkService
{
    public const CODE_TTL_SECONDS = 900;

    public function __construct(
        private ClientWhatsAppChannel $whatsapp = new ClientWhatsAppChannel(),
        private ?Client $http = null,
    ) {
        $this->http ??= new Client(['timeout' => 20, 'http_errors' => false]);
    }

    public static function parseTelegramStartPayload(?string $text): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        if (preg_match('/^\/start(?:@[A-Za-z0-9_]+)?(?:\s+(\S+))?/i', $text, $m)) {
            return self::normalizeCode($m[1] ?? '');
        }

        if (preg_match('/\b(mp[a-f0-9]{8})\b/i', $text, $m)) {
            return self::normalizeCode($m[1]);
        }

        return self::normalizeCode($text);
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code === '' || !preg_match('/^mp[a-f0-9]{8}$/', $code)) {
            return '';
        }

        return $code;
    }

    /**
     * @return array{
     *   linked: bool,
     *   chat_id: string,
     *   bot_username: string,
     *   deep_link: string,
     *   code: string,
     *   ready: bool,
     *   error: ?string
     * }
     */
    public function telegramStatus(MediaUser $user): array
    {
        $tenantId = (int) ($user->tenant_id ?? 1);
        $chatId = normalize_telegram_chat_id($user->telegram_chat_id ?? null);
        $bot = $this->botUsername($tenantId);
        $ready = $bot !== '';

        return [
            'linked' => $chatId !== '',
            'chat_id' => $chatId,
            'bot_username' => $bot,
            'deep_link' => '',
            'code' => '',
            'ready' => $ready,
            'error' => $ready ? null : 'Falta el bot de Telegram (Configuración → Telegram).',
        ];
    }

    /**
     * @return array{success: bool, message: string, deep_link: string, code: string, bot_username: string}
     */
    public function createTelegramLink(MediaUser $user): array
    {
        $tenantId = (int) ($user->tenant_id ?? 1);
        $bot = $this->botUsername($tenantId);
        if ($bot === '') {
            return [
                'success' => false,
                'message' => 'El administrador aún no ha configurado el bot de Telegram.',
                'deep_link' => '',
                'code' => '',
                'bot_username' => '',
            ];
        }

        $code = $this->issueCode($tenantId, (int) $user->id, 'telegram');
        $this->ensureTelegramWebhook($tenantId);

        return [
            'success' => true,
            'message' => 'Abre Telegram y pulsa Iniciar.',
            'deep_link' => 'https://t.me/' . rawurlencode($bot) . '?start=' . rawurlencode($code),
            'code' => $code,
            'bot_username' => $bot,
        ];
    }

    /** @return array{success: bool, message: string} */
    public function unlinkTelegram(MediaUser $user): array
    {
        $user->telegram_chat_id = null;
        $user->save();
        AuditService::log('media_user.telegram_unlinked', 'media_user', (int) $user->id, null, [
            'via' => 'portal',
        ]);

        return ['success' => true, 'message' => 'Telegram desvinculado.'];
    }

    /**
     * @return array{
     *   phone: string,
     *   opted_in: bool,
     *   can_auto: bool,
     *   wa_link: string
     * }
     */
    public function whatsappStatus(MediaUser $user): array
    {
        $tenantId = (int) ($user->tenant_id ?? 1);
        $phone = $this->whatsapp->userPhone($user);
        $alerts = new AlertSettingsService();
        $display = $alerts->whatsappCloudDisplayPhone($tenantId);
        $code = $this->latestOpenCode((int) $user->id, 'whatsapp');
        $waLink = '';
        if ($display !== '' && $code !== '') {
            $waLink = 'https://wa.me/' . $display . '?text=' . rawurlencode($code);
        }

        return [
            'phone' => $phone,
            'opted_in' => $this->whatsapp->optedIn($user),
            'can_auto' => $this->whatsapp->cloudConfigured($tenantId) || $this->whatsapp->canSend($user, $tenantId),
            'wa_link' => $waLink,
        ];
    }

    /**
     * @return array{success: bool, message: string, phone: ?string, wa_link: string}
     */
    public function saveWhatsApp(MediaUser $user, string $phone, bool $optIn): array
    {
        $clean = ClientWhatsAppChannel::normalizePhone($phone);
        if ($phone !== '' && $clean === '') {
            return [
                'success' => false,
                'message' => 'Ese número no parece válido. Pon el móvil con prefijo (España: 6xx o 7xx).',
                'phone' => null,
                'wa_link' => '',
            ];
        }

        $user->metaSet('whatsapp_phone', $clean !== '' ? $clean : null);
        $user->metaSet('whatsapp_opt_in', $optIn && $clean !== '');
        $user->save();

        $waLink = '';
        if ($clean !== '' && $optIn) {
            $tenantId = (int) ($user->tenant_id ?? 1);
            $display = (new AlertSettingsService())->whatsappCloudDisplayPhone($tenantId);
            if ($display !== '') {
                $code = $this->issueCode($tenantId, (int) $user->id, 'whatsapp');
                $waLink = 'https://wa.me/' . $display . '?text=' . rawurlencode($code);
            }
        }

        AuditService::log('media_user.whatsapp_updated', 'media_user', (int) $user->id, null, [
            'whatsapp_phone' => $clean !== '' ? $clean : null,
            'whatsapp_opt_in' => $optIn && $clean !== '',
            'via' => 'portal',
        ]);

        if ($clean === '') {
            return [
                'success' => true,
                'message' => 'WhatsApp quitado.',
                'phone' => null,
                'wa_link' => '',
            ];
        }

        return [
            'success' => true,
            'message' => $optIn
                ? 'Número guardado. Si el administrador tiene WhatsApp Business, te avisaremos ahí.'
                : 'Número guardado, sin avisos automáticos.',
            'phone' => $clean,
            'wa_link' => $waLink,
        ];
    }

    /**
     * @param array<string, mixed> $update
     * @return array{ok: bool, handled: bool}
     */
    public function handleTelegramUpdate(int $tenantId, array $update): array
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!is_array($message)) {
            return ['ok' => true, 'handled' => false];
        }

        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $type = (string) ($chat['type'] ?? '');
        $chatId = trim((string) ($chat['id'] ?? ''));
        $text = (string) ($message['text'] ?? $message['caption'] ?? '');
        if ($chatId === '' || $type !== 'private') {
            return ['ok' => true, 'handled' => false];
        }

        $code = self::parseTelegramStartPayload($text);
        if ($code === '') {
            if (str_starts_with(ltrim($text), '/start')) {
                $this->replyTelegram($tenantId, $chatId, "Hola. Para vincular los avisos, abre el portal → Mi ficha → Vincular Telegram.");
            }

            return ['ok' => true, 'handled' => false];
        }

        $row = $this->consumeCode($tenantId, $code, 'telegram');
        if ($row === null) {
            $this->replyTelegram($tenantId, $chatId, 'Ese código no vale o ya caducó. Vuelve al portal y pulsa Vincular otra vez.');

            return ['ok' => true, 'handled' => true];
        }

        $user = MediaUser::find((int) $row['media_user_id']);
        if ($user === null) {
            return ['ok' => true, 'handled' => true];
        }

        $user->telegram_chat_id = $chatId;
        $user->save();
        AuditService::log('media_user.telegram_linked', 'media_user', (int) $user->id, null, [
            'telegram_chat_id' => $chatId,
            'via' => 'portal',
        ]);

        $name = trim((string) ($user->display_name ?: $user->username ?: ''));
        $hello = $name !== '' ? "¡Hola, {$name}!" : '¡Listo!';
        $this->replyTelegram($tenantId, $chatId, $hello . " Ya te avisamos por Telegram cuando se acerque la fecha o pidas la contraseña.");

        return ['ok' => true, 'handled' => true];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, handled: bool}
     */
    public function handleWhatsAppCloudPayload(int $tenantId, array $payload): array
    {
        $handled = false;
        $entries = $payload['entry'] ?? [];
        if (!is_array($entries)) {
            return ['ok' => true, 'handled' => false];
        }

        foreach ($entries as $entry) {
            $changes = is_array($entry) ? ($entry['changes'] ?? []) : [];
            if (!is_array($changes)) {
                continue;
            }
            foreach ($changes as $change) {
                $value = is_array($change) ? ($change['value'] ?? []) : [];
                $messages = is_array($value) ? ($value['messages'] ?? []) : [];
                if (!is_array($messages)) {
                    continue;
                }
                foreach ($messages as $msg) {
                    if (!is_array($msg)) {
                        continue;
                    }
                    $from = ClientWhatsAppChannel::normalizePhone((string) ($msg['from'] ?? ''));
                    $text = (string) (($msg['text']['body'] ?? '') ?: '');
                    $code = self::parseTelegramStartPayload($text);
                    if ($from === '') {
                        continue;
                    }
                    if ($code !== '') {
                        $row = $this->consumeCode($tenantId, $code, 'whatsapp');
                        if ($row !== null) {
                            $user = MediaUser::find((int) $row['media_user_id']);
                            if ($user !== null) {
                                $user->metaSet('whatsapp_phone', $from);
                                $user->metaSet('whatsapp_opt_in', true);
                                $user->save();
                                $handled = true;
                            }
                            continue;
                        }
                    }
                    // Si el número ya estaba guardado, marcar opt-in al escribir al negocio.
                    $this->optInByPhone($tenantId, $from);
                    $handled = true;
                }
            }
        }

        return ['ok' => true, 'handled' => $handled];
    }

    public function telegramWebhookSecret(int $tenantId): string
    {
        $cfg = TelegramConfig::forTenant($tenantId);

        return trim((string) ($cfg['webhook_secret'] ?? ''));
    }

    public function verifyTelegramSecret(int $tenantId, ?string $header): bool
    {
        $secret = $this->telegramWebhookSecret($tenantId);
        if ($secret === '') {
            return true;
        }

        return is_string($header) && hash_equals($secret, $header);
    }

    /**
     * @return array{success: bool, message: string, username: string, url: string}
     */
    public function ensureTelegramWebhook(int $tenantId): array
    {
        $cfg = TelegramConfig::forTenant($tenantId);
        $token = trim((string) ($cfg['bot_token'] ?? ''));
        if ($token === '') {
            return ['success' => false, 'message' => 'Falta el Bot Token.', 'username' => '', 'url' => ''];
        }

        $base = $this->publicHttpsBase();
        if ($base === null) {
            return [
                'success' => false,
                'message' => 'El webhook de Telegram necesita HTTPS. Pon APP_URL=https://tudominio.com',
                'username' => (string) ($cfg['bot_username'] ?? ''),
                'url' => '',
            ];
        }

        $url = $base . '/webhooks/telegram/' . $tenantId;
        $secret = $this->telegramWebhookSecret($tenantId);
        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
            $this->persistTelegramSetting($tenantId, 'telegram_webhook_secret', $secret);
        }

        $username = $this->botUsername($tenantId);

        try {
            $response = $this->http->post('https://api.telegram.org/bot' . $token . '/setWebhook', [
                'json' => [
                    'url' => $url,
                    'secret_token' => $secret,
                    'allowed_updates' => ['message'],
                    'drop_pending_updates' => false,
                ],
            ]);
            $json = json_decode((string) $response->getBody(), true);
            $ok = is_array($json) && !empty($json['ok']);
            if (!$ok) {
                $desc = is_array($json) ? (string) ($json['description'] ?? 'error') : 'error';

                return ['success' => false, 'message' => 'Telegram: ' . $desc, 'username' => $username, 'url' => $url];
            }
        } catch (GuzzleException $e) {
            Logger::error('Telegram setWebhook failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'No se pudo activar el webhook.', 'username' => $username, 'url' => $url];
        }

        return [
            'success' => true,
            'message' => 'Vinculación del portal activa. Los clientes pueden pulsar Vincular Telegram.',
            'username' => $username,
            'url' => $url,
        ];
    }

    public function botUsername(int $tenantId): string
    {
        $cfg = TelegramConfig::forTenant($tenantId);
        $stored = ltrim(trim((string) ($cfg['bot_username'] ?? '')), '@');
        if ($stored !== '') {
            return $stored;
        }

        $token = trim((string) ($cfg['bot_token'] ?? ''));
        if ($token === '') {
            return '';
        }

        try {
            $response = $this->http->get('https://api.telegram.org/bot' . $token . '/getMe');
            $json = json_decode((string) $response->getBody(), true);
            $username = is_array($json) ? ltrim((string) ($json['result']['username'] ?? ''), '@') : '';
            if ($username !== '') {
                $this->persistTelegramSetting($tenantId, 'telegram_bot_username', $username);

                return $username;
            }
        } catch (GuzzleException $e) {
            Logger::debug('Telegram getMe failed', ['error' => $e->getMessage()]);
        }

        return '';
    }

    private function publicHttpsBase(): ?string
    {
        $configured = rtrim((string) config('app.url', env('APP_URL', '')), '/');
        $configuredLooksLocal = $configured === ''
            || str_contains($configured, 'localhost')
            || str_contains($configured, '127.0.0.1');

        $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
        $host = trim(explode(',', $host)[0]);
        $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $https = $proto === 'https'
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');

        if ($host !== '' && $https) {
            return 'https://' . $host;
        }

        if (!$configuredLooksLocal && str_starts_with(strtolower($configured), 'https://')) {
            return $configured;
        }

        return null;
    }

    private function issueCode(int $tenantId, int $mediaUserId, string $channel): string
    {
        self::ensureTable();
        $code = 'mp' . bin2hex(random_bytes(4));
        $expires = date('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS);

        try {
            Database::getInstance()->query(
                'DELETE FROM media_user_link_codes
                 WHERE media_user_id = ? AND channel = ? AND used_at IS NULL',
                [$mediaUserId, $channel]
            );
            Database::getInstance()->insert('media_user_link_codes', [
                'tenant_id' => $tenantId,
                'media_user_id' => $mediaUserId,
                'channel' => $channel,
                'code' => $code,
                'expires_at' => $expires,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Logger::error('link code insert failed', ['error' => $e->getMessage()]);
        }

        return $code;
    }

    private function latestOpenCode(int $mediaUserId, string $channel): string
    {
        self::ensureTable();
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT code FROM media_user_link_codes
                 WHERE media_user_id = ? AND channel = ? AND used_at IS NULL AND expires_at > ?
                 ORDER BY id DESC LIMIT 1',
                [$mediaUserId, $channel, date('Y-m-d H:i:s')]
            );
        } catch (\Throwable) {
            return '';
        }

        return $row ? (string) $row['code'] : '';
    }

    /** @return array<string, mixed>|null */
    private function consumeCode(int $tenantId, string $code, string $channel): ?array
    {
        self::ensureTable();
        $code = self::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT * FROM media_user_link_codes
                 WHERE tenant_id = ? AND channel = ? AND code = ? AND used_at IS NULL AND expires_at > ?
                 LIMIT 1',
                [$tenantId, $channel, $code, date('Y-m-d H:i:s')]
            );
        } catch (\Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        Database::getInstance()->update(
            'media_user_link_codes',
            ['used_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [(int) $row['id']]
        );

        return $row;
    }

    private function optInByPhone(int $tenantId, string $phone): void
    {
        try {
            $rows = Database::getInstance()->fetchAll(
                'SELECT id, metadata FROM media_users
                 WHERE tenant_id = ? AND deleted_at IS NULL AND metadata LIKE ?
                 LIMIT 20',
                [$tenantId, '%' . $phone . '%']
            );
        } catch (\Throwable) {
            return;
        }

        foreach ($rows as $row) {
            $user = new MediaUser($row);
            if ($this->whatsapp->userPhone($user) === $phone) {
                $user->metaSet('whatsapp_opt_in', true);
                $user->save();
            }
        }
    }

    private function replyTelegram(int $tenantId, string $chatId, string $text): void
    {
        $cfg = TelegramConfig::forTenant($tenantId);
        $token = trim((string) ($cfg['bot_token'] ?? ''));
        if ($token === '') {
            return;
        }

        try {
            $this->http->post('https://api.telegram.org/bot' . $token . '/sendMessage', [
                'json' => [
                    'chat_id' => $chatId,
                    'text' => $text,
                ],
            ]);
        } catch (GuzzleException $e) {
            Logger::debug('Telegram link reply failed', ['error' => $e->getMessage()]);
        }
    }

    private function persistTelegramSetting(int $tenantId, string $key, string $value): void
    {
        $db = Database::getInstance();
        try {
            $existing = $db->fetchOne(
                'SELECT id FROM settings WHERE tenant_id = ? AND `group` = ? AND `key` = ?',
                [$tenantId, 'telegram', $key]
            );
            if ($existing) {
                $db->update('settings', ['value' => $value], 'id = ?', [$existing['id']]);
            } else {
                $db->insert('settings', [
                    'tenant_id' => $tenantId,
                    'group' => 'telegram',
                    'key' => $key,
                    'value' => $value,
                    'type' => 'string',
                ]);
            }
        } catch (\Throwable $e) {
            Logger::debug('persist telegram setting failed', ['error' => $e->getMessage()]);
        }
    }

    public static function ensureTable(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT COUNT(*) AS total FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                ['media_user_link_codes']
            );
            if (((int) ($row['total'] ?? 0)) > 0) {
                $ensured = true;
                return;
            }
        } catch (\Throwable) {
            // Fall through.
        }

        try {
            (new Updater())->runMigrations();
        } catch (\Throwable) {
        }

        try {
            Database::getInstance()->pdo()->exec(
                'CREATE TABLE IF NOT EXISTS `media_user_link_codes` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id` BIGINT UNSIGNED NOT NULL,
                    `media_user_id` BIGINT UNSIGNED NOT NULL,
                    `channel` VARCHAR(20) NOT NULL DEFAULT \'telegram\',
                    `code` VARCHAR(32) NOT NULL,
                    `expires_at` DATETIME NOT NULL,
                    `used_at` DATETIME NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_link_codes_code` (`code`),
                    KEY `idx_link_codes_user_channel` (`media_user_id`, `channel`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable) {
            return;
        }

        $ensured = true;
    }
}
