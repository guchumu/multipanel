<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Models\Server;
use App\Services\Notifications\ClientWhatsAppChannel;
use Core\Database;
use Core\Logger;
use Core\Updater;
use DateTimeImmutable;

/**
 * Invitar a volver a caducados: 4 plantillas en orden, enlace mágico 1 año, repetición periódica.
 */
final class ReengageCampaignService
{
    private const GROUP = 'reengage';
    private const KEY = 'config';
    private const INVITE_SLOTS = 4;

    public function __construct(
        private MediaUserManagementService $management = new MediaUserManagementService(),
        private ClientWhatsAppChannel $whatsapp = new ClientWhatsAppChannel(),
        private AlertSettingsService $alerts = new AlertSettingsService(),
        private PortalLoginLinkService $portalLinks = new PortalLoginLinkService(),
    ) {
    }

    /**
     * @return array{
     *   enabled: bool, interval_days: int, max_sends: int, min_expired_days: int, trial_days: int,
     *   discount_percent: int, link_ttl_days: int,
     *   invites: list<array{label: string, title: string, body: string}>,
     *   title: string, body: string, trial_title: string, trial_body: string
     * }
     */
    public function getConfig(int $tenantId): array
    {
        $defaults = $this->defaultConfig();

        $row = Database::getInstance()->fetchOne(
            'SELECT value FROM settings WHERE tenant_id = ? AND `group` = ? AND `key` = ?',
            [$tenantId, self::GROUP, self::KEY]
        );
        if (!$row || !is_string($row['value']) || trim($row['value']) === '') {
            return $defaults;
        }

        $decoded = json_decode($row['value'], true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        $invites = $this->normalizeInvites($decoded, $defaults['invites']);
        $first = $invites[0];

        // Default antiguo de la campaña era 3; el producto arranca reenganche a los 60 días.
        $minExpired = max(1, (int) ($decoded['min_expired_days'] ?? $defaults['min_expired_days']));
        if ($minExpired === 3) {
            $minExpired = 60;
        }

        return [
            'enabled' => array_key_exists('enabled', $decoded) ? (bool) $decoded['enabled'] : $defaults['enabled'],
            'interval_days' => max(1, (int) ($decoded['interval_days'] ?? $defaults['interval_days'])),
            'max_sends' => max(1, min(self::INVITE_SLOTS, (int) ($decoded['max_sends'] ?? $defaults['max_sends']))),
            'min_expired_days' => $minExpired,
            'trial_days' => max(1, min(15, (int) ($decoded['trial_days'] ?? $defaults['trial_days']))),
            'discount_percent' => max(0, min(90, (int) ($decoded['discount_percent'] ?? $defaults['discount_percent']))),
            'link_ttl_days' => max(30, min(365, (int) ($decoded['link_ttl_days'] ?? $defaults['link_ttl_days']))),
            'invites' => $invites,
            'title' => $first['title'],
            'body' => $first['body'],
            'trial_title' => trim((string) ($decoded['trial_title'] ?? $defaults['trial_title'])) ?: $defaults['trial_title'],
            'trial_body' => (string) ($decoded['trial_body'] ?? $defaults['trial_body']),
        ];
    }

    /** @param array<string, mixed> $input */
    public function saveConfig(int $tenantId, array $input): void
    {
        $current = $this->getConfig($tenantId);
        $invites = $this->invitesFromInput($input, $current['invites']);
        $payload = [
            'enabled' => !empty($input['enabled']),
            'interval_days' => max(1, (int) ($input['interval_days'] ?? $current['interval_days'])),
            'max_sends' => max(1, min(self::INVITE_SLOTS, (int) ($input['max_sends'] ?? $current['max_sends']))),
            'min_expired_days' => max(1, (int) ($input['min_expired_days'] ?? $current['min_expired_days'])),
            'trial_days' => max(1, min(15, (int) ($input['trial_days'] ?? $current['trial_days']))),
            'discount_percent' => max(0, min(90, (int) ($input['discount_percent'] ?? $current['discount_percent']))),
            'link_ttl_days' => max(30, min(365, (int) ($input['link_ttl_days'] ?? $current['link_ttl_days']))),
            'invites' => array_map(static fn (array $m): array => [
                'title' => $m['title'],
                'body' => $m['body'],
            ], $invites),
            'title' => $invites[0]['title'],
            'body' => $invites[0]['body'],
            'trial_title' => trim((string) ($input['trial_title'] ?? $current['trial_title'])),
            'trial_body' => (string) ($input['trial_body'] ?? $current['trial_body']),
        ];

        $db = Database::getInstance();
        $value = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $existing = $db->fetchOne(
            'SELECT id FROM settings WHERE tenant_id = ? AND `group` = ? AND `key` = ?',
            [$tenantId, self::GROUP, self::KEY]
        );
        if ($existing) {
            $db->update('settings', ['value' => $value], 'id = ?', [$existing['id']]);
            return;
        }

        $db->insert('settings', [
            'tenant_id' => $tenantId,
            'group' => self::GROUP,
            'key' => self::KEY,
            'value' => $value,
            'type' => 'json',
        ]);
    }

    /**
     * @param array{trial_days?: int, discount_percent?: int, link_ttl_days?: int} $cfg
     */
    public function render(string $template, MediaUser $user, array $cfg, string $serverName = '', ?string $portalUrl = null): string
    {
        $expiresAt = (string) ($user->expires_at ?? '');
        $expiresDate = $expiresAt !== '' ? substr($expiresAt, 0, 10) : '';
        $endDate = $expiresDate !== '' ? (new DateTimeImmutable($expiresDate))->format('d/m/Y') : '';
        $daysLeft = days_left($user->expires_at) ?? 0;
        $ttlDays = max(30, min(365, (int) ($cfg['link_ttl_days'] ?? 365)));
        $linkYears = $ttlDays >= 365 ? '1' : (string) max(1, (int) round($ttlDays / 365));

        $serviceName = 'Plex';
        if ($serverName === '' && !empty($user->server_id)) {
            $server = Server::find((int) $user->server_id);
            if ($server) {
                $serverName = (string) $server->name;
                $type = strtolower((string) ($server->type ?? ''));
                if ($type === 'jellyfin') {
                    $serviceName = 'Jellyfin';
                }
            }
        } elseif (!empty($user->server_id)) {
            $server = Server::find((int) $user->server_id);
            if ($server && strtolower((string) ($server->type ?? '')) === 'jellyfin') {
                $serviceName = 'Jellyfin';
            }
        }

        if ($portalUrl === null || $portalUrl === '') {
            $portalUrl = $this->loginFallbackUrl();
        }

        $replace = [
            '{username}' => (string) ($user->username ?? ''),
            '{email}' => (string) ($user->email ?? ''),
            '{display_name}' => (string) ($user->display_name ?: $user->username ?: 'hola'),
            '{end_date}' => $endDate,
            '{days}' => (string) abs($daysLeft),
            '{days_left}' => (string) $daysLeft,
            '{server_name}' => $serverName !== '' ? $serverName : 'el servidor',
            '{service_name}' => $serviceName,
            '{trial_days}' => (string) (int) ($cfg['trial_days'] ?? 3),
            '{discount_percent}' => (string) (int) ($cfg['discount_percent'] ?? 15),
            '{link_years}' => $linkYears,
            '{portal_url}' => $portalUrl,
        ];

        return str_replace(array_keys($replace), array_values($replace), $template);
    }

    /**
     * Plantilla del aviso N (1..4) o de la prueba.
     *
     * @return array{title: string, body: string, step: int}|null
     */
    public function templateFor(array $cfg, string $kind, int $step = 1): ?array
    {
        if ($kind === 'trial') {
            $body = trim((string) ($cfg['trial_body'] ?? ''));
            if ($body === '') {
                return null;
            }

            return [
                'title' => (string) ($cfg['trial_title'] ?? 'Prueba lista'),
                'body' => $body,
                'step' => 0,
            ];
        }

        $invites = $cfg['invites'] ?? [];
        if (!is_array($invites) || $invites === []) {
            return null;
        }

        $index = max(0, min(count($invites) - 1, $step - 1));
        $invite = $invites[$index];
        $body = trim((string) ($invite['body'] ?? ''));
        if ($body === '') {
            return null;
        }

        return [
            'title' => (string) ($invite['title'] ?? 'Te echamos en falta'),
            'body' => $body,
            'step' => $index + 1,
        ];
    }

    /** @return array{success: bool, message: string, sent: bool} */
    public function invite(MediaUser $user, bool $force = true): array
    {
        $cfg = $this->getConfig((int) ($user->tenant_id ?? 1));
        if (!$cfg['enabled'] && !$force) {
            return ['success' => false, 'message' => 'La campaña de reenganche está desactivada.', 'sent' => false];
        }

        $sendCount = $this->sendCountFor((int) $user->id);
        $step = min(self::INVITE_SLOTS, $sendCount + 1);
        $tpl = $this->templateFor($cfg, 'invite', $step);
        if ($tpl === null) {
            return ['success' => false, 'message' => 'No hay texto de reenganche. Guárdalo en Mensajes a usuarios.', 'sent' => false];
        }

        $portalUrl = $this->magicPortalUrl($user, $cfg);
        $title = $this->render($tpl['title'], $user, $cfg, (string) ($user->server_name ?? ''), $portalUrl);
        $text = $this->render($tpl['body'], $user, $cfg, (string) ($user->server_name ?? ''), $portalUrl);
        $result = $this->management->sendClientNotice($user, $title, $text, 'reengage_invite');
        if (!empty($result['sent'])) {
            $this->recordSend($user, 'invite');
        }

        return $result;
    }

    /** @return array{success: bool, message: string, sent: bool} */
    public function openTrial(MediaUser $user): array
    {
        $cfg = $this->getConfig((int) ($user->tenant_id ?? 1));
        $days = (int) $cfg['trial_days'];
        $added = $this->management->addDays($user, $days);
        if (empty($added['success'])) {
            return ['success' => false, 'message' => (string) ($added['message'] ?? 'No se pudo abrir la prueba.'), 'sent' => false];
        }

        $fresh = MediaUser::find((int) $user->id) ?? $user;
        $fresh->server_name = $user->server_name ?? null;
        $tpl = $this->templateFor($cfg, 'trial');
        $portalUrl = $this->magicPortalUrl($fresh, $cfg);
        $title = $this->render((string) ($tpl['title'] ?? $cfg['trial_title']), $fresh, $cfg, (string) ($user->server_name ?? ''), $portalUrl);
        $text = $this->render((string) ($tpl['body'] ?? $cfg['trial_body']), $fresh, $cfg, (string) ($user->server_name ?? ''), $portalUrl);
        $notice = $this->management->sendClientNotice($fresh, $title, $text, 'reengage_trial');
        $this->recordSend($fresh, 'trial');

        $msg = (string) $added['message'];
        if (!empty($notice['sent'])) {
            $msg .= ' Aviso enviado.';
        } else {
            $msg .= ' ' . (string) ($notice['message'] ?? 'Sin canal para avisar.');
        }

        return ['success' => true, 'message' => $msg, 'sent' => !empty($notice['sent'])];
    }

    /**
     * Cron: marca conversiones y reenvía a caducados según intervalo.
     *
     * @return array{sent: int, skipped: int, converted: int, errors: int, deferred?: int}
     */
    public function run(int $tenantId): array
    {
        $cfg = $this->getConfig($tenantId);
        if (!$cfg['enabled']) {
            return ['sent' => 0, 'skipped' => 0, 'converted' => 0, 'errors' => 0];
        }

        $schedule = $this->alerts->expiryNotifySchedule($tenantId);
        if (!$this->alerts->isWithinExpiryNotifyWindow($schedule, $tenantId)) {
            return ['sent' => 0, 'skipped' => 0, 'converted' => 0, 'errors' => 0, 'deferred' => 1];
        }

        self::ensureTable();
        $converted = $this->markConversions($tenantId, (int) $cfg['trial_days']);

        $stats = ['sent' => 0, 'skipped' => 0, 'converted' => $converted, 'errors' => 0];
        $rows = Database::getInstance()->fetchAll(
            'SELECT mu.*, s.name AS server_name, r.send_count, r.last_sent_at, r.converted_at
             FROM media_users mu
             LEFT JOIN servers s ON s.id = mu.server_id AND s.deleted_at IS NULL
             LEFT JOIN media_user_reengage r ON r.media_user_id = mu.id
             WHERE mu.tenant_id = ? AND mu.deleted_at IS NULL
               AND mu.expires_at IS NOT NULL
               AND DATE(mu.expires_at) < CURDATE()
               AND DATEDIFF(CURDATE(), DATE(mu.expires_at)) >= ?
               AND mu.status IN (\'expired\', \'suspended\', \'active\')
               AND (r.converted_at IS NULL)
               AND COALESCE(r.send_count, 0) < ?
               AND (r.last_sent_at IS NULL OR r.last_sent_at <= DATE_SUB(NOW(), INTERVAL ? DAY))
             LIMIT 200',
            [$tenantId, $cfg['min_expired_days'], $cfg['max_sends'], $cfg['interval_days']]
        );

        foreach ($rows as $row) {
            $user = new MediaUser($row);
            $chatId = normalize_telegram_chat_id($user->telegram_chat_id ?? null);
            if ($chatId === '' && !$this->whatsapp->canSend($user, $tenantId)) {
                $stats['skipped']++;
                continue;
            }

            $result = $this->invite($user, false);
            if (!empty($result['sent'])) {
                $stats['sent']++;
                Logger::info('Reengage invite sent', ['media_user_id' => $user->id]);
            } else {
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /** @return array{contacted: int, sends: int, came_back: int, rate: int} */
    public function stats(int $tenantId): array
    {
        self::ensureTable();
        $row = Database::getInstance()->fetchOne(
            'SELECT COUNT(*) AS contacted,
                    COALESCE(SUM(send_count), 0) AS sends,
                    COALESCE(SUM(CASE WHEN converted_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS came_back
             FROM media_user_reengage
             WHERE tenant_id = ? AND send_count > 0',
            [$tenantId]
        );

        $contacted = (int) ($row['contacted'] ?? 0);
        $cameBack = (int) ($row['came_back'] ?? 0);

        return [
            'contacted' => $contacted,
            'sends' => (int) ($row['sends'] ?? 0),
            'came_back' => $cameBack,
            'rate' => $contacted > 0 ? (int) round(100 * $cameBack / $contacted) : 0,
        ];
    }

    public static function ensureTable(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        if (self::tableExists()) {
            $ensured = true;
            return;
        }
        try {
            (new Updater())->runMigrations();
        } catch (\Throwable) {
        }
        if (self::tableExists()) {
            $ensured = true;
            return;
        }
        try {
            Database::getInstance()->pdo()->exec(
                'CREATE TABLE IF NOT EXISTS `media_user_reengage` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id` BIGINT UNSIGNED NOT NULL,
                    `media_user_id` BIGINT UNSIGNED NOT NULL,
                    `send_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `last_sent_at` DATETIME NULL,
                    `last_kind` VARCHAR(20) NOT NULL DEFAULT \'invite\',
                    `converted_at` DATETIME NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_reengage_user` (`media_user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable) {
            return;
        }
        $ensured = true;
    }

    /** @return array{enabled: bool, interval_days: int, max_sends: int, min_expired_days: int, trial_days: int, discount_percent: int, link_ttl_days: int, invites: list<array{label: string, title: string, body: string}>, title: string, body: string, trial_title: string, trial_body: string} */
    private function defaultConfig(): array
    {
        $rawInvites = config('reengage.invites', []);
        $invites = $this->normalizeInvites(
            ['invites' => is_array($rawInvites) ? $rawInvites : []],
            $this->builtinInviteDefaults()
        );
        $first = $invites[0];

        return [
            'enabled' => (bool) config('reengage.enabled', true),
            'interval_days' => max(1, (int) config('reengage.interval_days', 14)),
            'max_sends' => max(1, min(self::INVITE_SLOTS, (int) config('reengage.max_sends', 4))),
            'min_expired_days' => max(1, (int) config('reengage.min_expired_days', 60)),
            'trial_days' => max(1, min(15, (int) config('reengage.trial_days', 3))),
            'discount_percent' => max(0, min(90, (int) config('reengage.discount_percent', 15))),
            'link_ttl_days' => max(30, min(365, (int) config('reengage.link_ttl_days', 365))),
            'invites' => $invites,
            'title' => $first['title'],
            'body' => $first['body'],
            'trial_title' => (string) config('reengage.trial_title', 'Prueba lista'),
            'trial_body' => (string) config('reengage.trial_body', ''),
        ];
    }

    /** @return list<array{label: string, title: string, body: string}> */
    private function builtinInviteDefaults(): array
    {
        return [
            ['label' => 'Aviso 1', 'title' => 'Te echamos en falta', 'body' => 'Hola {display_name}, vuelve a Plex: {portal_url}'],
            ['label' => 'Aviso 2', 'title' => 'Te echamos en falta', 'body' => 'Hola {display_name}, vuelve a Plex: {portal_url}'],
            ['label' => 'Aviso 3', 'title' => 'Te echamos en falta', 'body' => 'Hola {display_name}, vuelve a Plex: {portal_url}'],
            ['label' => 'Aviso 4', 'title' => 'Te echamos en falta', 'body' => 'Hola {display_name}, vuelve a Plex: {portal_url}'],
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     * @param list<array{label: string, title: string, body: string}> $defaults
     * @return list<array{label: string, title: string, body: string}>
     */
    private function normalizeInvites(array $decoded, array $defaults): array
    {
        $out = [];
        $raw = $decoded['invites'] ?? null;

        if (is_array($raw) && $raw !== []) {
            foreach (array_values($raw) as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $def = $defaults[$i] ?? $defaults[0];
                $title = trim((string) ($item['title'] ?? $def['title']));
                $body = (string) ($item['body'] ?? $def['body']);
                if ($title === '' && trim($body) === '') {
                    continue;
                }
                $out[] = [
                    'label' => (string) ($item['label'] ?? $def['label'] ?? ('Aviso ' . (count($out) + 1))),
                    'title' => $title !== '' ? $title : $def['title'],
                    'body' => $body !== '' ? $body : $def['body'],
                ];
            }
        }

        // Sin array invites: textos por defecto (no reutilizar el título/cuerpo único antiguo).
        if ($out === []) {
            return array_slice($defaults, 0, self::INVITE_SLOTS);
        }

        while (count($out) < self::INVITE_SLOTS) {
            $i = count($out);
            $out[] = $defaults[$i] ?? $defaults[0];
        }

        return array_slice($out, 0, self::INVITE_SLOTS);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<array{label: string, title: string, body: string}> $current
     * @return list<array{label: string, title: string, body: string}>
     */
    private function invitesFromInput(array $input, array $current): array
    {
        $out = [];
        for ($i = 0; $i < self::INVITE_SLOTS; $i++) {
            $n = $i + 1;
            $cur = $current[$i] ?? ['label' => 'Aviso ' . $n, 'title' => '', 'body' => ''];
            $title = trim((string) ($input['invite_title_' . $n] ?? $cur['title']));
            $body = (string) ($input['invite_body_' . $n] ?? $cur['body']);
            $out[] = [
                'label' => (string) ($cur['label'] ?? ('Aviso ' . $n)),
                'title' => $title !== '' ? $title : (string) $cur['title'],
                'body' => $body !== '' ? $body : (string) $cur['body'],
            ];
        }

        return $out;
    }

    private function magicPortalUrl(MediaUser $user, array $cfg): string
    {
        $ttl = max(30, min(365, (int) ($cfg['link_ttl_days'] ?? 365)));
        if (empty($user->id)) {
            return $this->demoMagicUrl();
        }

        $created = $this->portalLinks->create($user, 'pay', $ttl);
        if (!empty($created['success']) && !empty($created['url'])) {
            return (string) $created['url'];
        }

        return $this->loginFallbackUrl();
    }

    private function loginFallbackUrl(): string
    {
        return rtrim((string) config('app.url', ''), '/') . '/portal/login';
    }

    private function demoMagicUrl(): string
    {
        return rtrim((string) config('app.url', ''), '/') . '/u/EjemploEnlace1AnoPlexDemo';
    }

    private function sendCountFor(int $mediaUserId): int
    {
        if ($mediaUserId <= 0) {
            return 0;
        }
        self::ensureTable();
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT send_count FROM media_user_reengage WHERE media_user_id = ? LIMIT 1',
                [$mediaUserId]
            );
        } catch (\Throwable) {
            return 0;
        }

        return (int) ($row['send_count'] ?? 0);
    }

    private function recordSend(MediaUser $user, string $kind): void
    {
        self::ensureTable();
        $db = Database::getInstance();
        $now = date('Y-m-d H:i:s');
        $existing = $db->fetchOne(
            'SELECT id, send_count FROM media_user_reengage WHERE media_user_id = ? LIMIT 1',
            [(int) $user->id]
        );
        if ($existing) {
            $db->update('media_user_reengage', [
                'send_count' => (int) $existing['send_count'] + 1,
                'last_sent_at' => $now,
                'last_kind' => $kind,
            ], 'id = ?', [$existing['id']]);
            return;
        }

        $db->insert('media_user_reengage', [
            'tenant_id' => (int) ($user->tenant_id ?? 1),
            'media_user_id' => (int) $user->id,
            'send_count' => 1,
            'last_sent_at' => $now,
            'last_kind' => $kind,
        ]);
    }

    private function markConversions(int $tenantId, int $trialDays): int
    {
        try {
            $stmt = Database::getInstance()->query(
                'UPDATE media_user_reengage r
                 INNER JOIN media_users mu ON mu.id = r.media_user_id
                 SET r.converted_at = NOW()
                 WHERE r.tenant_id = ? AND r.converted_at IS NULL AND r.send_count > 0
                   AND mu.deleted_at IS NULL AND mu.status = \'active\'
                   AND mu.expires_at IS NOT NULL
                   AND DATE(mu.expires_at) > DATE_ADD(CURDATE(), INTERVAL ? DAY)',
                [$tenantId, $trialDays]
            );

            return $stmt->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function tableExists(): bool
    {
        try {
            $row = Database::getInstance()->fetchOne("SHOW TABLES LIKE 'media_user_reengage'");
            return $row !== null && $row !== [];
        } catch (\Throwable) {
            return false;
        }
    }
}
