<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Peticiones\FilmaffinityPage;
use Tests\TestCase;

final class FilmaffinityPageTest extends TestCase
{
    public function testIdFromTypicalUrls(): void
    {
        $this->assertSame('809297', FilmaffinityPage::idFromText('https://www.filmaffinity.com/es/film809297.html'));
        $this->assertSame('123', FilmaffinityPage::idFromText('https://www.filmaffinity.com/en/film123.html?ref=home'));
        $this->assertSame('809297', FilmaffinityPage::idFromText('mira /film809297.html'));
        $this->assertSame('', FilmaffinityPage::idFromText('https://www.imdb.com/title/tt0111161/'));
    }

    public function testPageAndFetchUrl(): void
    {
        $this->assertSame(
            'https://www.filmaffinity.com/es/film809297.html',
            FilmaffinityPage::pageUrl('809297')
        );
        $this->assertSame(
            'https://www.filmaffinity.com/es/film809297.html',
            FilmaffinityPage::fetchUrl('809297')
        );
        $this->assertStringContainsString(
            'api.scraperapi.com',
            FilmaffinityPage::fetchUrl('809297', 'abc123')
        );
        $this->assertStringContainsString(
            rawurlencode('https://www.filmaffinity.com/es/film809297.html'),
            FilmaffinityPage::fetchUrl('809297', 'abc123')
        );
    }

    public function testParseMetaPrefersLargeHttpsPoster(): void
    {
        $html = <<<'HTML'
<meta property="og:title" content="Dune (2021) - FilmAffinity">
<meta property="og:image" content="http://pics.filmaffinity.com/dune-809297-mmed.jpg">
HTML;

        $meta = FilmaffinityPage::parseMeta($html);

        $this->assertSame('Dune (2021)', $meta['title']);
        $this->assertSame('https://pics.filmaffinity.com/dune-809297-large.jpg', $meta['poster']);
    }

    public function testPreferLargePosterUpgradesProtocolAndSize(): void
    {
        $this->assertSame(
            'https://pics.filmaffinity.com/foo-large.jpg',
            FilmaffinityPage::preferLargePoster('//pics.filmaffinity.com/foo-small.jpg')
        );
    }
}
