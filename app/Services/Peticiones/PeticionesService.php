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
     * @return array{ok: bool, message: string}
     */
    public function aceptar(int $id): array
    {
        $row = $this->repo->find($id);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Petición no encontrada'];
        }

        $now = date('Y-m-d H:i:s');
        $this->repo->accept($id, $now);

        $corto = $this->shortTitle((string) ($row['nombrepeticion'] ?? ''));
        $mensaje = "Su petición de {$corto} ha sido aceptada. Se la avisaremos tan pronto esté disponible para su reproducción.";
        $this->notifyUser($row, 'Petición aceptada', $mensaje);

        return ['ok' => true, 'message' => 'Aceptada'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function subir(int $id): array
    {
        $row = $this->repo->find($id);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Petición no encontrada'];
        }

        $now = date('Y-m-d H:i:s');
        $this->repo->markUploaded($id, $now);

        $corto = $this->shortTitle((string) ($row['nombrepeticion'] ?? ''));
        $mensaje = "Su petición de {$corto} ha sido añadida al catálogo. Disfrute del contenido.";
        $this->notifyUser($row, 'Contenido disponible', $mensaje);

        return ['ok' => true, 'message' => 'Marcada como subida'];
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

        $now = date('Y-m-d H:i:s');
        $this->repo->markUploaded($id, $now);

        if ($notify) {
            $corto = $this->shortTitle((string) ($row['nombrepeticion'] ?? ''));
            $mensaje = "Su petición de {$corto} ha sido añadida al catálogo. Disfrute del contenido.";
            $this->notifyUser($row, 'Contenido disponible', $mensaje);
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

        $now = date('Y-m-d H:i:s');
        $this->repo->deny($id, $motivoId, $now);

        $corto = $this->shortTitle((string) ($row['nombrepeticion'] ?? ''));
        $motivo = $this->repo->motivoNombre($motivoId);
        $mensaje = "Su petición de {$corto} no ha podido ser aceptada. Motivo: {$motivo}\nLamentamos las molestias.";
        $this->notifyUser($row, 'Petición denegada', $mensaje);

        return ['ok' => true, 'message' => 'Denegada'];
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

        $this->repo->delete($id);

        return ['ok' => true, 'message' => 'Eliminada'];
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
        if ($this->repo->find($id) === null) {
            return ['ok' => false, 'message' => 'Petición no encontrada'];
        }

        $this->repo->updateTitle($id, $title);

        return ['ok' => true, 'message' => 'Título actualizado'];
    }

    /**
     * Alta manual (sin ScraperAPI).
     *
     * @return array{ok: bool, message: string, id?: int}
     */
    public function addManual(string $url, string $title, string $img = '', ?string $idusuario = null, ?string $username = null): array
    {
        $url = trim($url);
        $title = PeticionText::repair(trim($title));
        $img = trim($img);

        $imdbId = TmdbPeticionLookup::imdbIdFromText($url . ' ' . $title);
        if ($url === '' && $imdbId !== '') {
            $url = TmdbPeticionLookup::imdbUrl($imdbId);
        }
        if ($title === '' && $imdbId !== '') {
            $title = $imdbId;
        }

        if ($url === '' || $title === '') {
            return ['ok' => false, 'message' => 'URL y título son obligatorios (o un enlace IMDb)'];
        }

        $lookup = ['poster' => '', 'titulo' => ''];
        if (TmdbPeticionLookup::isWeakPoster($img) || $imdbId !== '') {
            $lookup = $this->tmdbLookup($title, false, $url);
            if (TmdbPeticionLookup::isWeakPoster($img) && $lookup['poster'] !== '') {
                $img = $lookup['poster'];
            }
            $tmdbTitle = PeticionText::repair(trim((string) ($lookup['titulo'] ?? '')));
            if ($tmdbTitle !== '' && $imdbId !== '' && (TmdbPeticionLookup::imdbIdFromText($title) !== '' || $title === $imdbId)) {
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
            if ($id <= 0 || $apiKey === '') {
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
                try {
                    $this->repo->updateImg($id, $poster);
                } catch (\Throwable $e) {
                    Logger::warning('No se pudo guardar carátula TMDb: ' . $e->getMessage(), ['id' => $id]);
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
     * @return array{ok: bool, message: string, id?: int, poster?: string, plataformas?: list<array{nombre: string, logo: string}>}
     */
    public function enrichCard(int $id, bool $force = false): array
    {
        $row = $this->repo->find($id);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Petición no encontrada'];
        }

        $apiKey = $this->tmdbApiKey();
        if ($apiKey === '') {
            return ['ok' => false, 'message' => 'Configura la clave TMDb en Configuración → Peticiones'];
        }

        $title = (string) ($row['nombrepeticion'] ?? '');
        $lookup = $this->tmdb()->lookup($title, $apiKey, $force, (string) ($row['url'] ?? ''));
        $poster = (string) ($lookup['poster'] ?? '');
        $plataformas = $lookup['plataformas'] ?? [];

        if ($poster !== '' && ($force || TmdbPeticionLookup::isWeakPoster((string) ($row['img'] ?? '')))) {
            try {
                $this->repo->updateImg($id, $poster);
            } catch (\Throwable $e) {
                Logger::warning('No se pudo guardar carátula TMDb: ' . $e->getMessage(), ['id' => $id]);
            }
        }

        if ($poster === '' && !TmdbPeticionLookup::isWeakPoster((string) ($row['img'] ?? ''))) {
            $poster = (string) $row['img'];
        }

        return [
            'ok' => true,
            'message' => isset($lookup['error']) ? (string) $lookup['error'] : 'OK',
            'id' => $id,
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
        return $this->tmdb()->lookup($title, $this->tmdbApiKey(), $force, $url);
    }

    private function tmdbApiKey(): string
    {
        return trim(PeticionesConfig::forTenant()['tmdb_api_key']);
    }

    private function shortTitle(string $title): string
    {
        return TmdbPeticionLookup::shortTitle($title);
    }

    /** @param array<string, mixed> $row */
    private function notifyUser(array $row, string $title, string $message): void
    {
        $chatId = trim((string) ($row['idusuario'] ?? ''));
        if ($chatId === '' || $chatId === '0') {
            Logger::warning('Petición sin idusuario (chat Telegram); no se notifica', [
                'id' => $row['id'] ?? null,
            ]);

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
