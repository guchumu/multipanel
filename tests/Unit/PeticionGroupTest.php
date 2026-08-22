<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Peticiones\PeticionGroup;
use Tests\TestCase;

final class PeticionGroupTest extends TestCase
{
    public function testMergesSameImdbEvenWithDifferentTitles(): void
    {
        $groups = PeticionGroup::group([
            $this->row(1, 'Cadena perpetua', 'https://www.imdb.com/title/tt0111161/', 'ana'),
            $this->row(2, 'The Shawshank Redemption', 'https://www.imdb.com/title/tt0111161/', 'luis'),
        ]);

        $this->assertCount(1, $groups);
        $card = PeticionGroup::toCard($groups[0]);
        $this->assertSame(2, $card['request_count']);
        $this->assertSame(['luis', 'ana'], array_column($card['requesters'], 'name'));
    }

    public function testMergesTitleOnlyWithImdbWhenTitlesMatch(): void
    {
        $groups = PeticionGroup::group([
            $this->row(10, 'Dune (2021)', 'https://www.imdb.com/title/tt1160419/', 'ana'),
            $this->row(11, 'Dune', '', 'marta'),
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(2, PeticionGroup::toCard($groups[0])['request_count']);
    }

    public function testKeepsDifferentMoviesSeparate(): void
    {
        $groups = PeticionGroup::group([
            $this->row(1, 'Dune', '', 'ana'),
            $this->row(2, 'Dune Part Two', '', 'luis'),
        ]);

        $this->assertCount(2, $groups);
    }

    public function testDoesNotMergePendingWithDenied(): void
    {
        $pending = $this->row(1, 'Dune', '', 'ana');
        $denied = $this->row(2, 'Dune', '', 'luis');
        $denied['activo'] = '0';
        $denied['idmotivo'] = '3';

        $groups = PeticionGroup::group([$pending, $denied], true);
        $this->assertCount(2, $groups);

        $across = PeticionGroup::group([$pending, $denied], false);
        $this->assertCount(1, $across);
    }

    public function testRequesterCountCollapsesSamePerson(): void
    {
        $groups = PeticionGroup::group([
            $this->row(3, 'Dune', '', 'ana'),
            $this->row(2, 'Dune (2021)', '', 'ana'),
            $this->row(1, 'Dune', '', 'luis'),
        ]);
        $card = PeticionGroup::toCard($groups[0]);

        $this->assertSame(3, $card['request_count']);
        $this->assertCount(2, $card['requesters']);
        $this->assertSame(2, $card['requesters'][0]['count']);
        $this->assertSame('ana', $card['requesters'][0]['name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $title, string $url, string $username): array
    {
        return [
            'id' => $id,
            'nombrepeticion' => $title,
            'url' => $url,
            'img' => '',
            'username' => $username,
            'idusuario' => '',
            'subido' => '0',
            'aceptado' => '0',
            'activo' => '1',
            'idmotivo' => '0',
            'fechapeticion' => '2026-01-01 12:00:00',
        ];
    }
}
