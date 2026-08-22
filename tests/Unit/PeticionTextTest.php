<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Peticiones\PeticionText;
use App\Services\Peticiones\TmdbPeticionLookup;
use Tests\TestCase;

final class PeticionTextTest extends TestCase
{
    public function testRepairsMojibakeTildes(): void
    {
        $this->assertSame(
            'Tadeo Jones y La Lámpara Maravillosa',
            PeticionText::repair('Tadeo Jones y La LÃ¡mpara Maravillosa')
        );
        $this->assertSame(
            'Spider-Man: Un nuevo día',
            PeticionText::repair('Spider-Man: Un nuevo dÃ­a')
        );
        $this->assertSame(
            'Enola Holmes 2 en español',
            PeticionText::repair('Enola Holmes 2 en espaÃ±ol')
        );
        $this->assertSame(
            'Tadeo Jones y La Lámpara Maravillosa',
            PeticionText::repair($this->asMojibake('Tadeo Jones y La Lámpara Maravillosa'))
        );
        $this->assertSame(
            'español',
            PeticionText::repair($this->asMojibake('español'))
        );
    }

    private function asMojibake(string $utf8): string
    {
        $out = mb_convert_encoding($utf8, 'UTF-8', 'Windows-1252');

        return is_string($out) ? $out : $utf8;
    }

    public function testDecodesHtmlEntitiesThenTildes(): void
    {
        $this->assertSame(
            'Minions & monstruos en español',
            PeticionText::repair('Minions &amp; monstruos en espaÃ±ol')
        );
        $this->assertSame('Coco', PeticionText::repair('Coco'));
        $this->assertFalse(PeticionText::needsRepair('Coco'));
        $this->assertTrue(PeticionText::needsRepair('dÃ­a'));
    }

    public function testTmdbSearchQueryUsesRepairedTitleWithoutLanguageSuffix(): void
    {
        $this->assertSame(
            'Enola Holmes 2',
            TmdbPeticionLookup::searchQuery('Enola Holmes 2 en espaÃ±ol')
        );
        $this->assertSame(
            'Minions & monstruos',
            TmdbPeticionLookup::searchQuery('Minions &amp; monstruos en español')
        );
        $this->assertSame(
            'Tadeo Jones y La Lámpara Maravillosa',
            TmdbPeticionLookup::searchQuery('Tadeo Jones y La LÃ¡mpara Maravillosa (2023)')
        );
    }
}
