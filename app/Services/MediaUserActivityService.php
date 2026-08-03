<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Unified activity timeline for a media user (audit + messages).
 */
final class MediaUserActivityService
{
    /** @return array<int, array<string, mixed>> */
    public function timeline(int $mediaUserId, int $limit = 80): array
    {
        $db = Database::getInstance();
        $events = [];

        foreach ($db->fetchAll(
            'SELECT action, entity_type, entity_id, old_values, new_values, created_at, user_id
             FROM audit_logs
             WHERE entity_type = ? AND entity_id = ?
             ORDER BY created_at DESC
             LIMIT ?',
            ['media_user', $mediaUserId, $limit]
        ) as $row) {
            $events[] = [
                'type' => 'audit',
                'at' => (string) $row['created_at'],
                'action' => (string) $row['action'],
                'label' => $this->labelForAction((string) $row['action']),
                'detail' => $this->formatAuditDetail($row),
                'icon' => $this->iconForAction((string) $row['action']),
            ];
        }

        foreach ($db->fetchAll(
            'SELECT message_type, title, body, status, sent_at, channel
             FROM media_user_messages
             WHERE media_user_id = ?
             ORDER BY sent_at DESC
             LIMIT ?',
            [$mediaUserId, $limit]
        ) as $row) {
            $events[] = [
                'type' => 'message',
                'at' => (string) $row['sent_at'],
                'action' => (string) $row['message_type'],
                'label' => 'Mensaje Telegram',
                'detail' => (string) ($row['body'] ?? ''),
                'icon' => 'chat-dots',
                'status' => (string) $row['status'],
            ];
        }

        usort($events, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($events, 0, $limit);
    }

    /** @return array<int, array<string, mixed>> */
    public function recentForTenant(int $tenantId, int $limit = 100): array
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            'SELECT al.action, al.entity_id, al.old_values, al.new_values, al.created_at,
                    mu.username, mu.email, mu.display_name, mu.uuid
             FROM audit_logs al
             INNER JOIN media_users mu ON mu.id = al.entity_id AND mu.tenant_id = ?
             WHERE al.entity_type = ?
             ORDER BY al.created_at DESC
             LIMIT ?',
            [$tenantId, 'media_user', $limit]
        );

        return array_map(static fn (array $row): array => [
            'at' => (string) $row['created_at'],
            'action' => (string) $row['action'],
            'label' => self::labelForActionStatic((string) $row['action']),
            'user' => (string) ($row['display_name'] ?? $row['username'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'entity_id' => (int) $row['entity_id'],
            'uuid' => (string) ($row['uuid'] ?? ''),
        ], $rows);
    }

    private function labelForAction(string $action): string
    {
        return self::labelForActionStatic($action);
    }

    private static function labelForActionStatic(string $action): string
    {
        return match ($action) {
            'media_user.created' => 'Usuario creado',
            'media_user.suspended' => 'Suspendido',
            'media_user.activated' => 'Activado',
            'media_user.expires_updated' => 'Fecha actualizada',
            'media_user.telegram_updated' => 'Telegram actualizado',
            'media_user.notes_updated' => 'Notas actualizadas',
            'media_user.days_added' => 'Días añadidos',
            'media_user.message_sent' => 'Mensaje enviado',
            'media_user.removed_from_server' => 'Quitado del servidor',
            'media_user.deleted' => 'Eliminado',
            'media_user.profile_updated' => 'Datos actualizados',
            'media_user.payment_link_created' => 'Enlace de pago generado',
            'media_user.payment_renewed' => 'Pago recibido (renovación)',
            default => str_replace(['media_user.', '_'], ['', ' '], $action),
        };
    }

    private function iconForAction(string $action): string
    {
        return match ($action) {
            'media_user.suspended' => 'pause-circle',
            'media_user.activated' => 'play-circle',
            'media_user.expires_updated', 'media_user.days_added' => 'calendar-event',
            'media_user.telegram_updated', 'media_user.message_sent' => 'telegram',
            'media_user.notes_updated' => 'journal-text',
            'media_user.removed_from_server' => 'person-x',
            'media_user.payment_link_created' => 'credit-card',
            'media_user.payment_renewed' => 'cash-coin',
            default => 'clock-history',
        };
    }

    /** @param array<string, mixed> $row */
    private function formatAuditDetail(array $row): string
    {
        $old = json_decode((string) ($row['old_values'] ?? ''), true);
        $new = json_decode((string) ($row['new_values'] ?? ''), true);

        if (!is_array($old) && !is_array($new)) {
            return '';
        }

        $parts = [];
        foreach (['status', 'expires_at', 'telegram_chat_id', 'notes', 'days_added', 'amount', 'currency', 'days'] as $field) {
            $o = is_array($old) ? ($old[$field] ?? null) : null;
            $n = is_array($new) ? ($new[$field] ?? null) : null;
            if ($o !== $n && ($o !== null || $n !== null)) {
                $parts[] = sprintf('%s: %s → %s', $field, $o ?? '—', $n ?? '—');
            }
        }

        return implode(' · ', $parts);
    }
}
