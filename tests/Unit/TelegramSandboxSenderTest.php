<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TelegramSandboxSender;
use Tests\TestCase;

final class TelegramSandboxSenderTest extends TestCase
{
    public function testSamplePlaceholdersIncludeCommonKeys(): void
    {
        $samples = TelegramSandboxSender::samplePlaceholders(7);

        $this->assertSame('usuario.demo', $samples['{username}']);
        $this->assertSame('7', $samples['{days_left}']);
        $this->assertSame('7', $samples['{days}']);
        $this->assertNotSame('', $samples['{end_date}']);
        $this->assertNotSame('', $samples['{server_name}']);
    }

    public function testRenderWithSamplesReplacesPlaceholders(): void
    {
        $out = TelegramSandboxSender::renderWithSamples(
            'Hola {display_name}, quedan {days_left} días ({end_date}) en {server_name}.',
            3
        );

        $this->assertStringContainsString('Usuario Demo', $out);
        $this->assertStringContainsString('3', $out);
        $this->assertStringContainsString('Servidor Demo', $out);
        $this->assertStringNotContainsString('{display_name}', $out);
        $this->assertStringNotContainsString('{days_left}', $out);
    }

    public function testNegativeMilestoneSamples(): void
    {
        $samples = TelegramSandboxSender::samplePlaceholders(-1);

        $this->assertSame('-1', $samples['{days_left}']);
        $this->assertSame('1', $samples['{days}']);
    }
}
