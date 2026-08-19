<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Notifications\ClientWhatsAppChannel;
use App\Services\PortalMessagingLinkService;
use Tests\TestCase;

final class PortalMessagingLinkServiceTest extends TestCase
{
    public function testParseStartWithPayload(): void
    {
        $this->assertSame('mpab12cd34', PortalMessagingLinkService::parseTelegramStartPayload('/start mpab12cd34'));
        $this->assertSame('mpab12cd34', PortalMessagingLinkService::parseTelegramStartPayload('/start@MyBot mpab12cd34'));
        $this->assertSame('mpab12cd34', PortalMessagingLinkService::parseTelegramStartPayload('Hola mpab12cd34 gracias'));
        $this->assertSame('mpab12cd34', PortalMessagingLinkService::parseTelegramStartPayload('mpab12cd34'));
    }

    public function testParseStartWithoutPayloadIsEmpty(): void
    {
        $this->assertSame('', PortalMessagingLinkService::parseTelegramStartPayload('/start'));
        $this->assertSame('', PortalMessagingLinkService::parseTelegramStartPayload('/start@MyBot'));
        $this->assertSame('', PortalMessagingLinkService::parseTelegramStartPayload('hola que tal'));
        $this->assertSame('', PortalMessagingLinkService::parseTelegramStartPayload(''));
    }

    public function testNormalizeCodeRejectsJunk(): void
    {
        $this->assertSame('', PortalMessagingLinkService::normalizeCode('abc'));
        $this->assertSame('', PortalMessagingLinkService::normalizeCode('mpZZZZZZZZ'));
        $this->assertSame('mpaabbccdd', PortalMessagingLinkService::normalizeCode('MPAABBCCDD'));
    }

    public function testNormalizeSpanishMobile(): void
    {
        $this->assertSame('34612345678', ClientWhatsAppChannel::normalizePhone('612 34 56 78'));
        $this->assertSame('34612345678', ClientWhatsAppChannel::normalizePhone('+34 612345678'));
        $this->assertSame('34612345678', ClientWhatsAppChannel::normalizePhone('0034612345678'));
        $this->assertSame('34612345678', ClientWhatsAppChannel::normalizePhone('34612345678'));
        $this->assertSame('', ClientWhatsAppChannel::normalizePhone('123'));
        $this->assertSame('', ClientWhatsAppChannel::normalizePhone(''));
    }
}
