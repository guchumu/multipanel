<?php

declare(strict_types=1);

namespace App\Services\Peticiones;

use App\Repositories\PeticionesRepository;

/**
 * Agrupa peticiones duplicadas (mismo IMDb, Filmaffinity o título).
 */
final class PeticionGroup
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<list<array<string, mixed>>>
     */
    public static function group(array $rows, bool $sameStatus = true): array
    {
        $n = count($rows);
        if ($n === 0) {
            return [];
        }

        $parent = [];
        for ($i = 0; $i < $n; $i++) {
            $parent[$i] = $i;
        }

        $find = static function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }

            return $i;
        };
        $union = static function (int $a, int $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };

        $indexByKey = [];
        foreach ($rows as $i => $row) {
            $status = $sameStatus ? self::statusBucket($row) : '*';
            foreach (self::identityKeys($row) as $key) {
                $full = $status . '|' . $key;
                if (isset($indexByKey[$full])) {
                    $union($i, $indexByKey[$full]);
                } else {
                    $indexByKey[$full] = $i;
                }
            }
        }

        $buckets = [];
        foreach ($rows as $i => $row) {
            $root = $find($i);
            $buckets[$root][] = $row;
        }

        $groups = array_values($buckets);
        usort($groups, static function (array $a, array $b): int {
            return self::maxId($b) <=> self::maxId($a);
        });

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $members
     * @return array<string, mixed>
     */
    public static function toCard(array $members): array
    {
        if ($members === []) {
            return [
                'id' => 0,
                'group_ids' => [],
                'request_count' => 0,
                'requesters' => [],
            ];
        }

        usort($members, static fn (array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
        $canonical = $members[0];

        foreach ($members as $row) {
            $title = trim((string) ($row['nombrepeticion'] ?? ''));
            if ($title !== '' && !self::isPlaceholderTitle($title)) {
                $canonical['nombrepeticion'] = $title;
                break;
            }
        }

        foreach ($members as $row) {
            if (!TmdbPeticionLookup::isWeakPoster(isset($row['img']) ? (string) $row['img'] : null)) {
                $canonical['img'] = $row['img'];
                break;
            }
        }

        $pickedUrl = '';
        foreach ($members as $row) {
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            if (TmdbPeticionLookup::imdbIdFromText($url) !== '' || TmdbPeticionLookup::filmaffinityIdFromText($url) !== '') {
                $pickedUrl = $url;
                break;
            }
            if ($pickedUrl === '') {
                $pickedUrl = $url;
            }
        }
        if ($pickedUrl !== '') {
            $canonical['url'] = $pickedUrl;
        }

        $seen = [];
        foreach ($members as $row) {
            $username = trim((string) ($row['username'] ?? ''));
            $chat = trim((string) ($row['idusuario'] ?? ''));
            if ($chat === '0') {
                $chat = '';
            }
            $label = $username !== '' ? $username : ($chat !== '' ? $chat : 'Sin nombre');
            $dedupe = $username !== ''
                ? 'u:' . mb_strtolower($username, 'UTF-8')
                : ($chat !== '' ? 'c:' . $chat : 'id:' . (int) ($row['id'] ?? 0));
            if (isset($seen[$dedupe])) {
                $seen[$dedupe]['count']++;
                continue;
            }
            $seen[$dedupe] = [
                'name' => $label,
                'fecha' => (string) ($row['fechapeticion'] ?? ''),
                'count' => 1,
            ];
        }

        $canonical['group_ids'] = array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $members
        )));
        $canonical['request_count'] = count($members);
        $canonical['requesters'] = array_values($seen);

        return $canonical;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function statusBucket(array $row): string
    {
        if ((string) ($row['subido'] ?? '0') === '1') {
            return 'subido';
        }
        $denied = ((string) ($row['activo'] ?? '1') === '0') || ((int) ($row['idmotivo'] ?? 0) > 0);
        if ($denied) {
            return PeticionesRepository::FILTER_DENEGADAS;
        }
        if ((string) ($row['aceptado'] ?? '0') === '1') {
            return PeticionesRepository::FILTER_PROCESO;
        }

        return PeticionesRepository::FILTER_PENDIENTES;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function identityKeys(array $row): array
    {
        $blob = (string) ($row['url'] ?? '') . ' ' . (string) ($row['nombrepeticion'] ?? '');
        $keys = [];
        $imdb = TmdbPeticionLookup::imdbIdFromText($blob);
        if ($imdb !== '') {
            $keys[] = 'imdb:' . $imdb;
        }
        $fa = TmdbPeticionLookup::filmaffinityIdFromText($blob);
        if ($fa !== '') {
            $keys[] = 'fa:' . $fa;
        }
        $title = self::titleKey((string) ($row['nombrepeticion'] ?? ''));
        if ($title !== '' && !self::isPlaceholderTitle($title)) {
            $keys[] = 'title:' . $title;
        }
        if ($keys === []) {
            $keys[] = 'id:' . (int) ($row['id'] ?? 0);
        }

        return $keys;
    }

    public static function titleKey(string $title): string
    {
        return mb_strtolower(TmdbPeticionLookup::searchQuery($title), 'UTF-8');
    }

    public static function isPlaceholderTitle(string $title): bool
    {
        $title = trim($title);
        if ($title === '') {
            return true;
        }
        if (TmdbPeticionLookup::imdbIdFromText($title) !== '' && preg_match('/^tt\d{7,}$/i', $title) === 1) {
            return true;
        }

        return preg_match('/^film\d+$/i', $title) === 1;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function maxId(array $rows): int
    {
        $max = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > $max) {
                $max = $id;
            }
        }

        return $max;
    }
}
