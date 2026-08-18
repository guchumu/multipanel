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
}
