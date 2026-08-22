<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Peticiones\TmdbPeticionLookup;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

final class TmdbPeticionLookupTest extends TestCase
{
    public function testShortTitleStripsYearInParentheses(): void
    {
        $this->assertSame('Dune', TmdbPeticionLookup::shortTitle('Dune (2021)'));
        $this->assertSame('The Boys', TmdbPeticionLookup::shortTitle('The Boys'));
    }

    public function testWeakPosterDetection(): void
    {
        $this->assertTrue(TmdbPeticionLookup::isWeakPoster(''));
        $this->assertTrue(TmdbPeticionLookup::isWeakPoster(null));
        $this->assertTrue(TmdbPeticionLookup::isWeakPoster('https://via.placeholder.com/300x450?text=Sin+poster'));
        $this->assertTrue(TmdbPeticionLookup::isWeakPoster('images/foo.jpg'));
        $this->assertTrue(TmdbPeticionLookup::isWeakPoster('https://image.tmdb.org/t/p/w500'));
        $this->assertTrue(TmdbPeticionLookup::isWeakPoster('https://image.tmdb.org/t/p/w500null'));
        $this->assertFalse(TmdbPeticionLookup::isWeakPoster('https://image.tmdb.org/t/p/w500/abc.jpg'));
        $this->assertFalse(TmdbPeticionLookup::isWeakPoster('https://cdn.example.com/poster.jpg'));
    }

    public function testPosterUrlIgnoresEmptyPath(): void
    {
        $this->assertSame('', TmdbPeticionLookup::posterUrl(null));
        $this->assertSame('', TmdbPeticionLookup::posterUrl(''));
        $this->assertSame('', TmdbPeticionLookup::posterUrl('null'));
        $this->assertSame(
            'https://image.tmdb.org/t/p/w500/pB8BM7pdSp6B6Ih7QZ4DrQ3PmJK.jpg',
            TmdbPeticionLookup::posterUrl('/pB8BM7pdSp6B6Ih7QZ4DrQ3PmJK.jpg')
        );
    }

    public function testLookupReturnsPosterAndSpainFlatrateLogos(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'results' => [
                    ['id' => 1, 'media_type' => 'person', 'name' => 'Alguien'],
                    [
                        'id' => 550,
                        'media_type' => 'movie',
                        'title' => 'El club de la lucha',
                        'poster_path' => '/pB8BM7pdSp6B6Ih7QZ4DrQ3PmJK.jpg',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'results' => [
                    'ES' => [
                        'flatrate' => [
                            ['provider_name' => 'Netflix', 'logo_path' => '/netflix.png'],
                            ['provider_name' => 'Disney Plus', 'logo_path' => '/disney.png'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
        $lookup = new TmdbPeticionLookup($client, false);

        $result = $lookup->lookup('El club de la lucha (1999)', 'test-key');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('El club de la lucha', $result['titulo']);
        $this->assertSame(
            'https://image.tmdb.org/t/p/w500/pB8BM7pdSp6B6Ih7QZ4DrQ3PmJK.jpg',
            $result['poster']
        );
        $this->assertCount(2, $result['plataformas']);
        $this->assertSame('Netflix', $result['plataformas'][0]['nombre']);
        $this->assertSame('https://image.tmdb.org/t/p/w92/netflix.png', $result['plataformas'][0]['logo']);
        $this->assertSame(
            ['Netflix', 'Disney Plus'],
            array_column($result['plataformas'], 'nombre')
        );
    }

    public function testLookupWithoutApiKeyDoesNotCallHttp(): void
    {
        $mock = new MockHandler([]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $lookup = new TmdbPeticionLookup($client, false);

        $result = $lookup->lookup('Dune', '');

        $this->assertSame('', $result['poster']);
        $this->assertSame([], $result['plataformas']);
        $this->assertSame('Sin clave TMDb.', $result['error'] ?? '');
    }

    public function testLookupEmptyResults(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['results' => []], JSON_THROW_ON_ERROR)),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
        $lookup = new TmdbPeticionLookup($client, false);

        $result = $lookup->lookup('xyzzy-not-a-title', 'test-key');

        $this->assertSame('', $result['poster']);
        $this->assertSame('No se encontraron resultados.', $result['error'] ?? '');
    }
}
