<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Peticiones\CatalogTitleMatcher;
use Tests\TestCase;

final class CatalogTitleMatcherTest extends TestCase
{
    public function testExactMatchIgnoresYearAndSpanishSuffix(): void
    {
        $this->assertTrue(CatalogTitleMatcher::matches(
            'Enola Holmes 2 en español',
            'Enola Holmes 2'
        ));
        $this->assertTrue(CatalogTitleMatcher::matches(
            'Tadeo Jones y La Lámpara Maravillosa',
            'Tadeo Jones y la lámpara maravillosa'
        ));
    }

    public function testShortTitlesNeedExactMatch(): void
    {
        $this->assertFalse(CatalogTitleMatcher::matches('Coco', 'Coco y el secreto'));
        $this->assertTrue(CatalogTitleMatcher::matches('Coco', 'Coco'));
    }

    public function testDoesNotMatchDifferentMovies(): void
    {
        $this->assertFalse(CatalogTitleMatcher::matches(
            'Spider-Man: Un nuevo día',
            'Spider-Man: No Way Home'
        ));
        $this->assertFalse(CatalogTitleMatcher::matches(
            'Minions & monstruos',
            'Minions'
        ));
    }
}
