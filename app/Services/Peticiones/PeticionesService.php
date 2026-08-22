<?php

declare(strict_types=1);

namespace App\Services\Peticiones;

use App\Repositories\PeticionesRepository;
use App\Services\Notifications\TelegramChannel;
use Core\Logger;
use Core\Session;

/**
 * Flujo admin de peticiones (paridad legacy) + avisos Telegram.
 */
final class PeticionesService
{
    /** @var list<array<string, mixed>>|null */
    private ?array $openCache = null;

    public function __construct(
        private PeticionesRepository $repo = new PeticionesRepository(),
        private ?TelegramChannel $telegram = null,
        private ?TmdbPeticionLookup $tmdb = null,
        private ?MediaCatalogSearchService $catalog = null,
    ) {
    }

    private function tmdb(): TmdbPeticionLookup
    {
        return $this->tmdb ??= new TmdbPeticionLookup();
    }

    private function catalog(): MediaCatalogSearchService
    {
        return $this->catalog ??= new MediaCatalogSearchService();
    }

    private function telegram(): TelegramChannel
    {
        return $this->telegram ??= new TelegramChannel();
    }

    /**
     * Listado admin agrupado por película (una ficha por título/IMDb/Filmaffinity).
     *
     * @return array{
     *   items: list<array<string, mixed>>,
     *   counts: array{pendientes: int, proceso: int, denegadas: int, todas: int}
     * }
     */
    public function adminBoard(string $filter, int $page, int $perPage): array
    {
        $open = $this->openRows();
        $cards = [
            PeticionesRepository::FILTER_PENDIENTES => [],
            PeticionesRepository::FILTER_PROCESO => [],
            PeticionesRepository::FILTER_DENEGADAS => [],
        ];
        foreach (PeticionGroup::group($open) as $members) {
            $card = PeticionGroup::toCard($members);
            $status = PeticionGroup::statusBucket($card);
            if (isset($cards[$status])) {
                $cards[$status][] = $card;
            }
        }

        $todas = array_merge(
            $cards[PeticionesRepository::FILTER_PENDIENTES],
            $cards[PeticionesRepository::FILTER_PROCESO],
            $cards[PeticionesRepository::FILTER_DENEGADAS],
        );
        usort($todas, static fn (array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));

        $counts = [
            'pendientes' => count($cards[PeticionesRepository::FILTER_PENDIENTES]),
            'proceso' => count($cards[PeticionesRepository::FILTER_PROCESO]),
            'denegadas' => count($cards[PeticionesRepository::FILTER_DENEGADAS]),
            'todas' => count($todas),
        ];

        $pool = match ($filter) {
            PeticionesRepository::FILTER_PROCESO => $cards[PeticionesRepository::FILTER_PROCESO],
            PeticionesRepository::FILTER_DENEGADAS => $cards[PeticionesRepository::FILTER_DENEGADAS],
            PeticionesRepository::FILTER_TODAS => $todas,
            default => $cards[PeticionesRepository::FILTER_PENDIENTES],
        };

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $items = array_slice($pool, ($page - 1) * $perPage, $perPage);

        return [
            'items' => $items,
            'counts' => $counts,
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function aceptar(int $id): array
    {
        $rows = $this->rowsInGroup($id, true);
        if ($rows === []) {
            return ['ok' => false, 'message' => 'Petición no encontrada'];
        }

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $this->repo->accept((int) $row['id'], $now);
        }
        $this->forgetOpenRows();

        $corto = $this->shortTitle((string) ($rows[0]['nombrepeticion'] ?? ''));
        $mensaje = "Su petición de {$corto} ha sido aceptada. Se la avisaremos tan pronto esté disponible para su reproducción.";
        $this->notifyGroup($rows, 'Petición aceptada', $mensaje);

        return ['ok' => true, 'message' => $this->groupActionMessage('Aceptada', $rows)];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function subir(int $id): array
    {
        $rows = $this->rowsInGroup($id, false);
        if ($rows === []) {
            return ['ok' => false, 'message' => 'Petición no encontrada'];
        }

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $this->repo->markUploaded((int) $row['id'], $now);
        }
        $this->forgetOpenRows();

        $corto = $this->shortTitle((string) ($rows[0]['nombrepeticion'] ?? ''));
        $mensaje = "Su petición de {$corto} ha sido añadida al catálogo. Disfrute del contenido.";
        $this->notifyGroup($rows, 'Contenido disponible', $mensaje);

        return ['ok' => true, 'message' => $this->groupActionMessage('Marcada como subida', $rows)];
    }

    /**
     * Si el título ya está en Plex/Jellyfin, lo marca como subido (sale de denegadas).
     *
     * @return array{ok: bool, found: bool, message: string, id?: int, server?: string}
     */
    public function markIfOnServer(int $id, bool $notify = true): array
    {
        $row = $this->repo->find($id);
        if ($row === null) {
            return ['ok' => false, 'found' => false, 'message' => 'Petición no encontrada'];
        }
        if ((string) ($row['subido'] ?? '0') === '1') {
            return ['ok' => true, 'found' => true, 'message' => 'Ya estaba marcada como subida', 'id' => $id];
        }

        $tenantId = (int) (Session::getInstance()->get('tenant_id') ?? 1);
        $hit = $this->catalog()->findTitle($tenantId, (string) ($row['nombrepeticion'] ?? ''));
        if ($hit === null) {
            return ['ok' => true, 'found' => false, 'message' => 'No está en el catálogo', 'id' => $id];
        }

        $rows = $this->rowsInGroup($id, false);
        if ($rows === []) {
            $rows = [$row];
        }

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $member) {
            if ((string) ($member['subido'] ?? '0') === '1') {
                continue;
            }
            $this->repo->markUploaded((int) $member['id'], $now);
        }
        $this->forgetOpenRows();

        if ($notify) {
            $corto = $this->shortTitle((string) ($row['nombrepeticion'] ?? ''));
            $mensaje = "Su petición de {$corto} ha sido añadida al catálogo. Disfrute del contenido.";
            $this->notifyGroup($rows, 'Contenido disponible', $mensaje);
        }

        $server = trim($hit['server']);
        $foundTitle = trim($hit['title']);

        return [
            'ok' => true,
            'found' => true,
            'message' => $server !== ''
                ? "Ya está en {$server}: {$foundTitle}"
                : "Ya está en el servidor: {$foundTitle}",
            'id' => $id,
            'server' => $server,
        ];
    }

    /**
     * Comprueba denegadas (u otros ids) contra el catálogo.
     *
     * @param list<int|string> $ids
     * @return array{ok: bool, message: string, updated: int, checked: int}
     */
    public function reconcileOnServer(array $ids): array
    {
        $updated = 0;
        $checked = 0;
        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }
            $checked++;
            $result = $this->markIfOnServer($id, true);
            if (!empty($result['ok']) && !empty($result['found']) && ($result['message'] ?? '') !== 'Ya estaba marcada como subida') {
                $updated++;
            }
        }

        return [
            'ok' => true,
            'updated' => $updated,
            'checked' => $checked,
            'message' => $updated > 0
                ? "Encontradas en el servidor y marcadas como subidas: {$updated}"
                : ($checked > 0 ? 'Ninguna de estas denegadas está ahora en el catálogo' : 'Nada que comprobar'),
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function denegar(int $id, int $motivoId): array
    {
        $row = $this->repo->find($id);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Petición no encontrada'];
        }
        if ($motivoId <= 0) {
            return ['ok' => false, 'message' => 'Selecciona un motivo'];
        }

        $rows = $this->rowsInGroup($id, true);
        if ($rows === []) {
            $rows = [$row];
        }

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $member) {
            $this->repo->deny((int) $member['id'], $motivoId, $now);
        }
        $this->forgetOpenRows();

        $corto = $this->shortTitle((string) ($row['nombrepeticion'] ?? ''));
        $motivo = $this->repo->motivoNombre($motivoId);
        $mensaje = "Su petición de {$corto} no ha podido ser aceptada. Motivo: {$motivo}\nLamentamos las molestias.";
        $this->notifyGroup($rows, 'Petición denegada', $mensaje);

        return ['ok' => true, 'message' => $this->groupActionMessage('Denegada', $rows)];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function borrar(int $id): array
    {
        $row = $this->repo->find($id);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Petición no encontrada'];
        }

        $rows = $this->rowsInGroup($id, true);
        if ($rows === []) {
            $rows = [$row];
        }
        foreach ($rows as $member) {
            $this->repo->delete((int) $member['id']);
        }
        $this->forgetOpenRows();

        return ['ok' => true, 'message' => $this->groupActionMessage('Eliminada', $rows, false)];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function rename(int $id, string $title): array
    {
        $title = PeticionText::repair(trim($title));
        if ($title === '') {
            return ['ok' => false, 'message' => 'Título vacío'];
        }
        $rows = $this->rowsInGroup($id, true);
        if ($rows === []) {
            return ['ok' => false, 'message' => 'Petición no encontrada'];
        }
        foreach ($rows as $member) {
            $this->repo->updateTitle((int) $member['id'], $title);
        }

        return ['ok' => true, 'message' => 'Título actualizado'];
    }

    /**
     * Alta manual. IMDb usa TMDb; Filmaffinity raspa og:image (ScraperAPI si está configurado).
     *
     * @return array{ok: bool, message: string, id?: int}
     */
    public function addManual(string $url, string $title, string $img = '', ?string $idusuario = null, ?string $username = null): array
    {
        $url = trim($url);
        $title = PeticionText::repair(trim($title));
        $img = trim($img);

        $imdbId = TmdbPeticionLookup::imdbIdFromText($url . ' ' . $title);
        $faId = TmdbPeticionLookup::filmaffinityIdFromText($url . ' ' . $title);
        if ($url === '' && $imdbId !== '') {
            $url = TmdbPeticionLookup::imdbUrl($imdbId);
        }
        if ($url === '' && $faId !== '') {
            $url = TmdbPeticionLookup::filmaffinityUrl($faId);
        }
        if ($title === '' && $imdbId !== '') {
            $title = $imdbId;
        }
        if ($title === '' && $faId !== '') {
            $title = 'film' . $faId;
        }

        if ($url === '' || $title === '') {
            return ['ok' => false, 'message' => 'URL y título son obligatorios (o un enlace IMDb / Filmaffinity)'];
        }

        $lookup = ['poster' => '', 'titulo' => ''];
        if (TmdbPeticionLookup::isWeakPoster($img) || $imdbId !== '' || $faId !== '') {
            $lookup = $this->tmdbLookup($title, false, $url);
            if (TmdbPeticionLookup::isWeakPoster($img) && $lookup['poster'] !== '') {
                $img = $lookup['poster'];
            }
            $tmdbTitle = PeticionText::repair(trim((string) ($lookup['titulo'] ?? '')));
            $titleIsLink = $imdbId !== '' && (TmdbPeticionLookup::imdbIdFromText($title) !== '' || $title === $imdbId);
            $titleIsFa = $faId !== '' && (TmdbPeticionLookup::filmaffinityIdFromText($title) !== '' || $title === 'film' . $faId);
            if ($tmdbTitle !== '' && ($titleIsLink || $titleIsFa)) {
                $title = $tmdbTitle;
            }
        }
        if (TmdbPeticionLookup::isWeakPoster($img)) {
            $img = 'https://via.placeholder.com/300x450?text=Sin+poster';
        }

        $id = $this->repo->insertManual([
            'url' => $url,
            'nombrepeticion' => $title,
            'img' => $img,
            'idusuario' => $idusuario,
            'username' => $username,
        ], date('Y-m-d H:i:s'));
        $this->forgetOpenRows();

        return ['ok' => true, 'message' => 'Petición añadida', 'id' => $id];
    }

    /**
     * Aplica metadatos TMDb ya cacheados (sin llamar a la API) y persiste carátula si `img` es débil.
     *
     * @param list<array<string, mixed>> $items
     * @return array{
     *   items: list<array<string, mixed>>,
     *   platformsById: array<int, list<array{nombre: string, logo: string}>>,
     *   needsTmdbIds: list<int>
     * }
     */
    public function applyCachedMetadata(array $items): array
    {
        $apiKey = $this->tmdbApiKey();
        $platformsById = [];
        $needsTmdbIds = [];

        foreach ($items as $i => $row) {
            $id = (int) ($row['id'] ?? 0);
            $title = (string) ($row['nombrepeticion'] ?? '');
            $url = (string) ($row['url'] ?? '');
            if ($id <= 0) {
                continue;
            }
            $hasFa = TmdbPeticionLookup::filmaffinityIdFromText($url . ' ' . $title) !== '';
            if ($apiKey === '' && !$hasFa) {
                continue;
            }

            $cached = $this->tmdb()->cached($title, $apiKey, $url);
            if ($cached === null) {
                $needsTmdbIds[] = $id;
                continue;
            }

            $platformsById[$id] = $cached['plataformas'];
            $poster = (string) ($cached['poster'] ?? '');
            if ($poster === '') {
                continue;
            }

            $current = (string) ($row['img'] ?? '');
            $items[$i]['img'] = $poster;
            if (TmdbPeticionLookup::isWeakPoster($current)) {
                $ids = $row['group_ids'] ?? [$id];
                foreach ($ids as $gid) {
                    $gid = (int) $gid;
                    if ($gid <= 0) {
                        continue;
                    }
                    try {
                        $this->repo->updateImg($gid, $poster);
                    } catch (\Throwable $e) {
                        Logger::warning('No se pudo guardar carátula TMDb: ' . $e->getMessage(), ['id' => $gid]);
                    }
                }
            }
        }

        return [
            'items' => $items,
            'platformsById' => $platformsById,
            'needsTmdbIds' => $needsTmdbIds,
        ];
    }

    /**
     * Consulta TMDb (caché 12 h), actualiza `img` si la carátula actual es débil.
     *
     * @return array{ok: bool, message: string, id?: int, titulo?: string, poster?: string, plataformas?: list<array{nombre: string, logo: string}>}
     */
    public function enrichCard(int $id, bool $force = false): array
    {
        $row = $this->repo->find($id);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Petición no encontrada'];
        }

        $apiKey = $this->tmdbApiKey();
        $url = (string) ($row['url'] ?? '');
        $title = (string) ($row['nombrepeticion'] ?? '');
        $faId = TmdbPeticionLookup::filmaffinityIdFromText($url . ' ' . $title);
        if ($apiKey === '' && $faId === '') {
            return ['ok' => false, 'message' => 'Configura la clave TMDb en Configuración → Peticiones'];
        }

        $lookup = $this->tmdb()->lookup($title, $apiKey, $force, $url, $this->scraperApiKey());
        $poster = (string) ($lookup['poster'] ?? '');
        $plataformas = $lookup['plataformas'] ?? [];
        $imdbId = TmdbPeticionLookup::imdbIdFromText($url . ' ' . $title);
        $lookupTitle = PeticionText::repair(trim((string) ($lookup['titulo'] ?? '')));
        $titleIsPlaceholder = ($imdbId !== '' && (TmdbPeticionLookup::imdbIdFromText($title) !== '' || $title === $imdbId))
            || ($faId !== '' && (TmdbPeticionLookup::filmaffinityIdFromText($title) !== '' || $title === 'film' . $faId));
        $group = $this->rowsInGroup($id, true);
        if ($group === []) {
            $group = [$row];
        }
        if ($lookupTitle !== '' && $titleIsPlaceholder && $lookupTitle !== $title) {
            foreach ($group as $member) {
                try {
                    $this->repo->updateTitle((int) $member['id'], $lookupTitle);
                } catch (\Throwable $e) {
                    Logger::warning('No se pudo guardar título de petición: ' . $e->getMessage(), ['id' => $member['id'] ?? null]);
                }
            }
            $title = $lookupTitle;
        }

        if ($poster !== '' && ($force || TmdbPeticionLookup::isWeakPoster((string) ($row['img'] ?? '')))) {
            foreach ($group as $member) {
                try {
                    $this->repo->updateImg((int) $member['id'], $poster);
                } catch (\Throwable $e) {
                    Logger::warning('No se pudo guardar carátula TMDb: ' . $e->getMessage(), ['id' => $member['id'] ?? null]);
                }
            }
        }

        if ($poster === '' && !TmdbPeticionLookup::isWeakPoster((string) ($row['img'] ?? ''))) {
            $poster = (string) $row['img'];
        }

        return [
            'ok' => true,
            'message' => isset($lookup['error']) ? (string) $lookup['error'] : 'OK',
            'id' => $id,
            'titulo' => $title,
            'poster' => $poster,
            'plataformas' => $plataformas,
        ];
    }

    /**
     * Recalcula carátulas/plataformas de las peticiones visibles (página actual).
     *
     * @param list<int|string> $ids
     * @return array{ok: bool, message: string, updated: int}
     */
    public function refreshMetadata(array $ids): array
    {
        $updated = 0;
        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }
            $result = $this->enrichCard($id, true);
            if (!empty($result['ok']) && ($result['poster'] ?? '') !== '') {
                $updated++;
            }
        }

        return [
            'ok' => true,
            'message' => $updated > 0
                ? "Carátulas actualizadas: {$updated}"
                : 'No se pudieron obtener carátulas TMDb',
            'updated' => $updated,
        ];
    }

    /**
     * Plataformas TMDb (watch providers ES). Vacío si no hay API key.
     *
     * @return list<string>
     */
    public function streamingPlatforms(string $title): array
    {
        return $this->tmdb()->platformNames($title, $this->tmdbApiKey());
    }

    /**
     * @return array{titulo: string, poster: string, plataformas: list<array{nombre: string, logo: string}>, error?: string}
     */
    public function tmdbLookup(string $title, bool $force = false, string $url = ''): array
    {
        return $this->tmdb()->lookup($title, $this->tmdbApiKey(), $force, $url, $this->scraperApiKey());
    }

    private function tmdbApiKey(): string
    {
        return trim(PeticionesConfig::forTenant()['tmdb_api_key']);
    }

    private function scraperApiKey(): string
    {
        return trim(PeticionesConfig::forTenant()['scraper_api_key']);
    }

    private function shortTitle(string $title): string
    {
        return TmdbPeticionLookup::shortTitle($title);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openRows(): array
    {
        return $this->openCache ??= $this->repo->listOpen();
    }

    private function forgetOpenRows(): void
    {
        $this->openCache = null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsInGroup(int $id, bool $sameStatus): array
    {
        $row = $this->repo->find($id);
        if ($row === null) {
            return [];
        }

        $open = $this->openRows();
        $found = false;
        foreach ($open as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $id) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $open[] = $row;
        }

        foreach (PeticionGroup::group($open, $sameStatus) as $members) {
            foreach ($members as $member) {
                if ((int) ($member['id'] ?? 0) === $id) {
                    return $members;
                }
            }
        }

        return [$row];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function groupActionMessage(string $done, array $rows, bool $notified = true): string
    {
        $n = count($rows);
        if ($n <= 1) {
            return $done;
        }
        if (!$notified) {
            return $done . ' (' . $n . ' solicitudes)';
        }

        return $done . ' (' . $n . ' solicitudes, avisados todos)';
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function notifyGroup(array $rows, string $title, string $message): void
    {
        $seen = [];
        $notified = 0;
        foreach ($rows as $row) {
            $chatId = trim((string) ($row['idusuario'] ?? ''));
            if ($chatId === '' || $chatId === '0' || isset($seen[$chatId])) {
                continue;
            }
            $seen[$chatId] = true;
            $this->notifyUser($row, $title, $message, false);
            $notified++;
        }
        if ($notified === 0) {
            Logger::warning('Petición agrupada sin idusuario (chat Telegram); no se notifica', [
                'ids' => array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows),
            ]);
        }
    }

    /** @param array<string, mixed> $row */
    private function notifyUser(array $row, string $title, string $message, bool $warnMissing = true): void
    {
        $chatId = trim((string) ($row['idusuario'] ?? ''));
        if ($chatId === '' || $chatId === '0') {
            if ($warnMissing) {
                Logger::warning('Petición sin idusuario (chat Telegram); no se notifica', [
                    'id' => $row['id'] ?? null,
                ]);
            }

            return;
        }

        $tenantId = (int) (Session::getInstance()->get('tenant_id') ?? 1);

        try {
            $this->telegram()->send($title, $message, [
                'chat_id' => $chatId,
                'user_message' => true,
                'tenant_id' => $tenantId,
                'parse_mode' => 'HTML',
                'message_type' => 'peticion',
            ]);
        } catch (\Throwable $e) {
            Logger::warning('Telegram petición falló: ' . $e->getMessage());
        }
    }
}
