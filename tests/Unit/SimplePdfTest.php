<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\SimplePdf;
use Tests\TestCase;

final class SimplePdfTest extends TestCase
{
    public function testGeneratesValidPdfHeader(): void
    {
        $pdf = new SimplePdf();
        $pdf->addPage();
        $pdf->addTextLine('Test Invoice INV-001');
        $output = $pdf->output();

        $this->assertStringStartsWith('%PDF-1.4', $output);
        $this->assertStringContainsString('%%EOF', $output);
    }
}
