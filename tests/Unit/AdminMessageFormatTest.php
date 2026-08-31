<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Notifications\AdminMessageFormat;
use PHPUnit\Framework\TestCase;

final class AdminMessageFormatTest extends TestCase
{
    public function testComposeJoinsSectionsWithBlankLine(): void
    {
        $text = AdminMessageFormat::compose([
            'Primera sección.',
            'Segunda sección.',
        ]);

        $this->assertSame("Primera sección.\n\nSegunda sección.", $text);
    }

    public function testToTelegramHtmlBoldLabels(): void
    {
        $html = AdminMessageFormat::toTelegramHtml("Motivo:\nError de red\n\nNota:\nRevisar token");

        $this->assertStringContainsString('<b>Motivo:</b>', $html);
        $this->assertStringContainsString('<b>Nota:</b>', $html);
        $this->assertStringContainsString('Error de red', $html);
    }
}
