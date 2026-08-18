<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Import\SqlInsertParser;
use App\Services\PlexManagerImportService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PlexManagerImportDateTest extends TestCase
{
    public function test_cast_token_keeps_long_numeric_ids_as_string(): void
    {
        $ref = new ReflectionMethod(SqlInsertParser::class, 'castToken');
        $ref->setAccessible(true);

        $this->assertSame(1, $ref->invoke(null, '1'));
        $this->assertSame('10379938', $ref->invoke(null, '10379938'));
        $this->assertSame('2023182976', $ref->invoke(null, '2023182976'));
        $this->assertSame('-1001234567890', $ref->invoke(null, '-1001234567890'));
    }

    public function test_date_to_datetime_normalizes_formats(): void
    {
        $service = new PlexManagerImportService();
        $ref = new ReflectionMethod(PlexManagerImportService::class, 'dateToDatetime');
        $ref->setAccessible(true);

        $this->assertSame('2026-12-31 23:59:59', $ref->invoke($service, '2026-12-31'));
        $this->assertSame('2026-12-31 23:59:59', $ref->invoke($service, '31/12/2026'));
        $this->assertSame('2026-01-15 12:30:00', $ref->invoke($service, '2026-01-15 12:30:00'));
        $this->assertNull($ref->invoke($service, '0000-00-00'));
        $this->assertNull($ref->invoke($service, ''));
    }

    public function test_resolve_telegram_prefers_chat_id_then_telegram_id(): void
    {
        $service = new PlexManagerImportService();
        $ref = new ReflectionMethod(PlexManagerImportService::class, 'resolveTelegramChatId');
        $ref->setAccessible(true);

        $this->assertSame('2023182976', $ref->invoke($service, [
            'telegram_chat_id' => '2023182976',
            'telegram_id' => '999',
        ]));
        $this->assertSame('2023182976', $ref->invoke($service, [
            'telegram_chat_id' => null,
            'telegram_id' => 2023182976,
        ]));
        $this->assertSame('2023182976', $ref->invoke($service, [
            'telegram_id' => '2023182976',
        ]));
        $this->assertSame('10379938', $ref->invoke($service, [
            'telegram_chat_id' => '',
            'telegram_id' => '10379938',
        ]));
        $this->assertSame('50063059', $ref->invoke($service, [
            'tg_id' => '50063059',
        ]));
        $this->assertNull($ref->invoke($service, [
            'telegram_id' => 'not-a-chat',
        ]));
    }

    public function test_resolve_expires_at_aliases(): void
    {
        $service = new PlexManagerImportService();
        $ref = new ReflectionMethod(PlexManagerImportService::class, 'resolveExpiresAt');
        $ref->setAccessible(true);

        $this->assertSame('2026-12-01 23:59:59', $ref->invoke($service, ['end_date' => '2026-12-01']));
        $this->assertSame('2026-12-01 23:59:59', $ref->invoke($service, ['expires_at' => '2026-12-01']));
        $this->assertSame('2026-12-01 23:59:59', $ref->invoke($service, ['expiration' => '01/12/2026']));
        $this->assertNull($ref->invoke($service, ['end_date' => null, 'expires_at' => '']));
    }

    public function test_resolve_notes_aliases(): void
    {
        $service = new PlexManagerImportService();
        $ref = new ReflectionMethod(PlexManagerImportService::class, 'resolveNotes');
        $ref->setAccessible(true);

        $this->assertSame('hola', $ref->invoke($service, ['private_notes' => 'hola']));
        $this->assertSame('admin', $ref->invoke($service, ['private_notes' => '', 'admin_notes' => 'admin']));
        $this->assertSame("linea1\nlinea2", $ref->invoke($service, ['notes' => "linea1\\nlinea2"]));
        $this->assertNull($ref->invoke($service, ['private_notes' => '   ', 'notes' => null]));
    }

    public function test_panel_fields_payload_skips_empty(): void
    {
        $service = new PlexManagerImportService();
        $ref = new ReflectionMethod(PlexManagerImportService::class, 'panelFieldsPayload');
        $ref->setAccessible(true);

        $this->assertSame([
            'expires_at' => '2026-12-01 23:59:59',
            'telegram_chat_id' => '12345',
            'notes' => 'x',
        ], $ref->invoke($service, '2026-12-01 23:59:59', '12345', 'x'));

        $this->assertSame([], $ref->invoke($service, null, '', '  '));
        $this->assertSame(
            ['telegram_chat_id' => '99999'],
            $ref->invoke($service, null, '99999', null)
        );
    }

    public function test_overlay_column_payload_maps_email_and_panel_fields(): void
    {
        $service = new PlexManagerImportService();
        $ref = new ReflectionMethod(PlexManagerImportService::class, 'overlayColumnPayload');
        $ref->setAccessible(true);

        $this->assertSame([
            'expires_at' => '2026-12-01 23:59:59',
            'telegram_chat_id' => '2023182976',
            'notes' => 'nota',
            'email' => 'user@example.com',
        ], $ref->invoke(
            $service,
            'User@Example.com',
            '2026-12-01 23:59:59',
            '2023182976',
            'nota'
        ));

        $this->assertSame(
            ['email' => 'a@b.com'],
            $ref->invoke($service, 'a@b.com', null, null, null)
        );
    }

    public function test_legacy_dump_row_maps_like_overlay_payload(): void
    {
        $service = new PlexManagerImportService();
        $tg = new ReflectionMethod(PlexManagerImportService::class, 'resolveTelegramChatId');
        $tg->setAccessible(true);
        $exp = new ReflectionMethod(PlexManagerImportService::class, 'resolveExpiresAt');
        $exp->setAccessible(true);
        $notes = new ReflectionMethod(PlexManagerImportService::class, 'resolveNotes');
        $notes->setAccessible(true);
        $overlay = new ReflectionMethod(PlexManagerImportService::class, 'overlayColumnPayload');
        $overlay->setAccessible(true);

        // Sample from plex_manager.sql users id=5 (masked email kept for mapping).
        $legacy = [
            'id' => 5,
            'server_id' => 1,
            'email' => 'arevalo.maria81@gmail.com',
            'telegram_id' => '6160743237',
            'telegram_chat_id' => '1172092710',
            'plex_user_id' => '202222492',
            'plex_username' => 'arevalo.21',
            'end_date' => '2027-01-01',
            'private_notes' => '',
        ];

        $payload = $overlay->invoke(
            $service,
            (string) $legacy['email'],
            $exp->invoke($service, $legacy),
            $tg->invoke($service, $legacy),
            $notes->invoke($service, $legacy)
        );

        $this->assertSame('arevalo.maria81@gmail.com', $payload['email']);
        $this->assertSame('1172092710', $payload['telegram_chat_id']);
        $this->assertSame('2027-01-01 23:59:59', $payload['expires_at']);
        $this->assertArrayNotHasKey('notes', $payload);

        // telegram_id only (no chat_id) — coalesce path.
        $legacyOnlyId = [
            'telegram_chat_id' => null,
            'telegram_id' => '6508105414',
            'end_date' => '2026-11-02',
            'private_notes' => 'hola',
            'email' => 'pbprenedo@gmail.com',
        ];
        $payload2 = $overlay->invoke(
            $service,
            (string) $legacyOnlyId['email'],
            $exp->invoke($service, $legacyOnlyId),
            $tg->invoke($service, $legacyOnlyId),
            $notes->invoke($service, $legacyOnlyId)
        );
        $this->assertSame('6508105414', $payload2['telegram_chat_id']);
        $this->assertSame('hola', $payload2['notes']);
    }

    public function test_telegram_from_history_tables_by_legacy_user_id(): void
    {
        $sql = <<<'SQL'
INSERT INTO `telegram_messages_history` (`id`, `user_id`, `telegram_chat_id`, `message`, `sent_date`, `status`, `sent_by`, `created_at`) VALUES
(1, 46, '50063059', 'hola', '2025-09-11 19:38:02', 'sent', 'admin', '2025-09-11 17:38:02');

INSERT INTO `notification_log` (`id`, `user_id`, `telegram_id`, `message_type`, `message_sent`, `sent_date`) VALUES
(1, 28, 2023182976, 'expiry_3', 'msg', '2025-09-21 15:04:31');
SQL;

        $service = new PlexManagerImportService();
        $ref = new ReflectionMethod(PlexManagerImportService::class, 'telegramChatIdByLegacyUserId');
        $ref->setAccessible(true);
        $map = $ref->invoke($service, $sql);

        $this->assertSame('50063059', $map[46]);
        $this->assertSame('2023182976', $map[28]);
    }
}
