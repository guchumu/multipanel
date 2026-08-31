<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\MediaUserMessageService;
use App\Services\TelegramConfig;
use Core\Logger;
use Core\Session;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Telegram bot notification channel.
 *
 * Mensajes con URLs (p. ej. Stripe cs_live_…) usan HTML para que los `_`
 * no se interpreten como cursiva de Markdown y se borren del enlace.
 */
final class TelegramChannel implements NotificationChannelInterface
{
    private Client $client;

    private string $botToken;

    private string $adminChatId;

    public function __construct(
        private ?string $botTokenOverride = null,
        private ?string $chatIdOverride = null,
        private MediaUserMessageService $messageLog = new MediaUserMessageService(),
    ) {
        $tenantId = (int) (Session::getInstance()->get('tenant_id') ?? 1);
        $cfg = TelegramConfig::forTenant($tenantId);
        $this->botToken = trim((string) ($botTokenOverride ?: $cfg['bot_token']));
        $this->adminChatId = trim((string) ($chatIdOverride ?: $cfg['admin_chat_id']));
        $this->client = new Client(['timeout' => 15]);
    }

    public function send(string $title, string $message, array $data = []): bool
    {
        $tenantId = isset($data['tenant_id']) ? (int) $data['tenant_id'] : (int) (Session::getInstance()->get('tenant_id') ?? 1);
        $cfg = TelegramConfig::forTenant($tenantId);
        $botToken = trim((string) ($this->botTokenOverride ?: $cfg['bot_token']));
        $adminChatId = trim((string) ($this->chatIdOverride ?: $cfg['admin_chat_id']));
        $intended = trim((string) ($data['chat_id'] ?? $adminChatId));
        $isUserMessage = !empty($data['media_user_id']) || !empty($data['log_message']) || !empty($data['user_message']);

        // Mensajes a usuarios: respetan sandbox. Alertas admin usan chat admin directo.
        $targets = $isUserMessage
            ? TelegramConfig::resolveOutboundChatIds($intended, $tenantId)
            : ($intended !== '' ? [$intended] : []);

        if ($botToken === '' || $targets === []) {
            Logger::warning('Telegram not configured', [
                'tenant_id' => $tenantId,
                'has_token' => $botToken !== '',
                'has_admin_chat' => $adminChatId !== '',
            ]);
            return false;
        }

        // Usar token resuelto en este envío (cron no tiene Session).
        $this->botToken = $botToken;
        $this->adminChatId = $adminChatId;

        $sandboxNote = null;
        if ($isUserMessage && $cfg['sandbox_enabled'] && $cfg['sandbox_chat_id'] !== '') {
            $userHint = !empty($data['media_user_id']) ? 'user ' . (int) $data['media_user_id'] : 'user';
            $destHint = $intended !== '' ? $intended : '—';
            $sandboxNote = "SANDBOX → {$userHint} / chat {$destHint}";
        }

        $override = isset($data['parse_mode']) ? (string) $data['parse_mode'] : null;
        $formatted = self::formatMessage($title, $message, $sandboxNote, $override);

        $anySent = false;
        foreach ($targets as $chatId) {
            if (isset($data['buttons']) && is_array($data['buttons'])) {
                $sent = $this->sendWithKeyboard((string) $chatId, $formatted['text'], $data['buttons'], $formatted['parse_mode']);
            } else {
                $sent = $this->sendPlain((string) $chatId, $formatted['text'], $formatted['parse_mode']);
            }
            $anySent = $anySent || $sent;
        }

        if (!empty($data['log_message']) || !empty($data['media_user_id'])) {
            $this->messageLog->log(
                isset($data['media_user_id']) ? (int) $data['media_user_id'] : null,
                (string) ($data['message_type'] ?? 'telegram'),
                $message,
                $title,
                (string) ($targets[0] ?? $intended),
                'telegram',
                $anySent
            );
        }

        return $anySent;
    }

    /**
     * Construye el texto y el parse_mode seguros para Telegram.
     *
     * - Si hay URLs http(s): HTML + <a href> (preserva `_` en cs_live_…).
     * - Si no: Markdown clásico (*título*).
     * - parse_mode override: 'HTML' | 'Markdown' | '' (sin parse_mode).
     *
     * @return array{text: string, parse_mode: ?string}
     */
    public static function formatMessage(
        string $title,
        string $message,
        ?string $sandboxNote = null,
        ?string $parseModeOverride = null,
    ): array {
        if ($parseModeOverride === '') {
            $text = $title . "\n\n" . $message;
            if ($sandboxNote !== null && $sandboxNote !== '') {
                $text .= "\n\n[" . $sandboxNote . ']';
            }

            return ['text' => $text, 'parse_mode' => null];
        }

        if ($parseModeOverride === 'Markdown') {
            $body = AdminMessageFormat::normalizeSpacing($message);
            $text = '*' . $title . "*\n\n" . $body;
            if ($sandboxNote !== null && $sandboxNote !== '') {
                $text .= "\n\n_[" . $sandboxNote . ']_';
            }

            return ['text' => $text, 'parse_mode' => 'Markdown'];
        }

        $text = '<b>' . self::escapeHtml($title) . "</b>\n\n"
            . AdminMessageFormat::toTelegramHtml($message);
        if ($sandboxNote !== null && $sandboxNote !== '') {
            $text .= "\n\n<i>" . self::escapeHtml($sandboxNote) . '</i>';
        }

        return ['text' => $text, 'parse_mode' => 'HTML'];
    }

    public static function containsHttpUrl(string $text): bool
    {
        return (bool) preg_match('#https?://[^\s<>"\']+#i', $text);
    }

    /**
     * Escapa HTML y convierte URLs en <a href="…">…</a>.
     */
    public static function linkifyHtml(string $message): string
    {
        $parts = preg_split('#(https?://[^\s<>"\']+)#i', $message, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return self::escapeHtml($message);
        }

        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $href = self::escapeHtmlAttr($part);
                $label = self::escapeHtml($part);
                $out .= '<a href="' . $href . '">' . $label . '</a>';
            } else {
                $out .= self::escapeHtml($part);
            }
        }

        return $out;
    }

    public static function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function escapeHtmlAttr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sendPlain(string $chatId, string $text, ?string $parseMode): bool
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];
        if ($parseMode !== null && $parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        try {
            $this->client->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                'json' => $payload,
            ]);
            return true;
        } catch (GuzzleException $e) {
            Logger::error('Telegram notification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** @param array<int, array{text: string, callback_data: string}> $buttons */
    private function sendWithKeyboard(string $chatId, string $text, array $buttons, ?string $parseMode): bool
    {
        $keyboard = array_map(fn ($btn) => [['text' => $btn['text'], 'callback_data' => $btn['callback_data']]], $buttons);

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ];
        if ($parseMode !== null && $parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        try {
            $this->client->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                'json' => $payload,
            ]);
            return true;
        } catch (GuzzleException $e) {
            Logger::error('Telegram keyboard message failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getName(): string
    {
        return 'telegram';
    }
}
