<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Notifications\WhatsAppAdminText;
use PHPUnit\Framework\TestCase;

final class WhatsAppAdminTextTest extends TestCase
{
    public function testPreviewLineIsFirstAndIncludesTitle(): void
    {
        $text = WhatsAppAdminText::wrap('RESUMEN DIARIO', "Caducidades hoy: 2\nTickets: 1", 'digest');
        $first = explode("\n", $text)[0];

        $this->assertStringStartsWith('📋 RESUMEN DIARIO · ', $first);
        $this->assertStringContainsString('*RESUMEN DIARIO*', $text);
        $this->assertMatchesRegularExpression('/\d{2}\/\d{2} \d{2}:\d{2}/', $first);
    }

    public function testSandboxKindUsesTestTubeIcon(): void
    {
        $text = WhatsAppAdminText::wrap('SANDBOX: se habría cortado', 'Momento: ahora', 'sandbox');
        $this->assertStringStartsWith('🧪 ', explode("\n", $text)[0]);
    }
}
