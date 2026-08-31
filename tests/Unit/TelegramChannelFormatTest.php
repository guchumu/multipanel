<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Notifications\TelegramChannel;
use Tests\TestCase;

final class TelegramChannelFormatTest extends TestCase
{
    public function testStripeUrlUsesHtmlParseModeAndPreservesUnderscores(): void
    {
        $url = 'https://checkout.stripe.com/c/pay/cs_live_a1b2_c3d4';
        $formatted = TelegramChannel::formatMessage(
            'Pago pendiente',
            "Para renovar tu acceso, completa el pago aquí:\n{$url}"
        );

        $this->assertSame('HTML', $formatted['parse_mode']);
        $this->assertStringContainsString('cs_live_a1b2_c3d4', $formatted['text']);
        $this->assertStringNotContainsString('cslivea1b2c3d4', $formatted['text']);
        $this->assertStringContainsString('<a href="' . $url . '">' . $url . '</a>', $formatted['text']);
        $this->assertStringContainsString('<b>Pago pendiente</b>', $formatted['text']);
    }

    public function testMessageWithoutUrlUsesHtmlWithBoldTitle(): void
    {
        $formatted = TelegramChannel::formatMessage('Aviso', "Motivo:\nTimeout de conexión.");

        $this->assertSame('HTML', $formatted['parse_mode']);
        $this->assertStringContainsString('<b>Aviso</b>', $formatted['text']);
        $this->assertStringContainsString('<b>Motivo:</b>', $formatted['text']);
    }

    public function testEmptyParseModeOverrideDisablesFormatting(): void
    {
        $url = 'https://checkout.stripe.com/c/pay/cs_live_xyz_123';
        $formatted = TelegramChannel::formatMessage('Pago', $url, null, '');

        $this->assertNull($formatted['parse_mode']);
        $this->assertStringContainsString($url, $formatted['text']);
        $this->assertStringNotContainsString('<a href', $formatted['text']);
    }

    public function testSandboxNoteUsesHtmlItalicWhenLinkifying(): void
    {
        $formatted = TelegramChannel::formatMessage(
            'Pago',
            'Paga en https://example.com/pay/cs_live_ab_cd',
            'SANDBOX → user 9 / chat 123'
        );

        $this->assertSame('HTML', $formatted['parse_mode']);
        $this->assertStringContainsString('<i>SANDBOX → user 9 / chat 123</i>', $formatted['text']);
    }
}
