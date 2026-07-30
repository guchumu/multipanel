<?php

declare(strict_types=1);

namespace Core;

/**
 * Minimal PDF 1.4 generator for invoices and reports.
 */
final class SimplePdf
{
    /** @var list<string> */
    private array $pages = [];

    private int $pageCount = 0;

    /** @var list<string> */
    private array $currentLines = [];

    private float $y = 800;

    public function addPage(): void
    {
        if (!empty($this->currentLines)) {
            $this->pages[] = $this->buildPageStream($this->currentLines);
            $this->currentLines = [];
        }
        $this->pageCount++;
        $this->y = 800;
    }

    public function text(float $x, float $y, string $text, int $size = 12): void
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $this->currentLines[] = "BT /F1 {$size} Tf {$x} {$y} Td ({$escaped}) Tj ET";
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->currentLines[] = "{$x1} {$y1} m {$x2} {$y2} l S";
    }

    public function addTextLine(string $text, float $x = 50, int $size = 12, float $lineHeight = 18): void
    {
        $this->text($x, $this->y, $text, $size);
        $this->y -= $lineHeight;
    }

    public function output(): string
    {
        if (!empty($this->currentLines)) {
            $this->pages[] = $this->buildPageStream($this->currentLines);
            $this->currentLines = [];
        }

        if ($this->pageCount === 0 && count($this->pages) === 0) {
            $this->addPage();
            $this->addTextLine('Empty document');
            $this->pages[] = $this->buildPageStream($this->currentLines);
            $this->currentLines = [];
        }

        $pageCount = count($this->pages);
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn ($i) => ($i + 2) . ' 0 R', range(0, $pageCount - 1))) . '] /Count ' . $pageCount . ' >>';

        for ($i = 0; $i < $pageCount; $i++) {
            $contentNum = 3 + ($i * 2);
            $pageNum = 4 + ($i * 2);
            $objects[$pageNum] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents {$contentNum} 0 R /Resources << /Font << /F1 " . (3 + $pageCount * 2) . " 0 R >> >> >>";
            $objects[$contentNum] = '<< /Length ' . strlen($this->pages[$i]) . " >>\nstream\n" . $this->pages[$i] . "\nendstream";
        }

        $fontNum = 3 + $pageCount * 2;
        $objects[$fontNum] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        $num = 1;
        foreach ($objects as $content) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$content}\nendobj\n";
            $num++;
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 {$num}\n0000000000 65535 f \n";
        for ($i = 1; $i < $num; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$num} /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }

    public function save(string $path): void
    {
        file_put_contents($path, $this->output());
    }

    /** @param list<string> $lines */
    private function buildPageStream(array $lines): string
    {
        return implode("\n", $lines);
    }
}
