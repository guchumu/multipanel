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
}
