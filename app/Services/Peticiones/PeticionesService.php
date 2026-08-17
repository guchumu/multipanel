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
    ) {
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
        $mensaje = "Su petición de {$corto} ha sido aceptada. Se la avisaremos tan pronto este disponible para su reproduccion.";
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
        $title = trim($title);
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
        $title = trim($title);
        $img = trim($img);

        if ($url === '' || $title === '') {
            return ['ok' => false, 'message' => 'URL y título son obligatorios'];
        }

        if ($img === '') {
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
     * Plataformas TMDb (watch providers ES). Vacío si no hay API key.
     *
     * @return list<string>
     */
    public function streamingPlatforms(string $title): array
    {
        $cfg = PeticionesConfig::forTenant();
        $apiKey = trim($cfg['tmdb_api_key']);
        if ($apiKey === '' || trim($title) === '') {
            return [];
        }

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 8]);
            $search = $client->get('https://api.themoviedb.org/3/search/multi', [
                'query' => [
                    'api_key' => $apiKey,
                    'query' => $this->shortTitle($title),
                    'language' => 'es-ES',
                    'include_adult' => 'false',
                ],
            ]);
            $data = json_decode((string) $search->getBody(), true);
            $first = $data['results'][0] ?? null;
            if (!is_array($first) || empty($first['id']) || empty($first['media_type'])) {
                return [];
            }

            $mediaType = (string) $first['media_type'];
            if (!in_array($mediaType, ['movie', 'tv'], true)) {
                return [];
            }

            $providers = $client->get(
                "https://api.themoviedb.org/3/{$mediaType}/{$first['id']}/watch/providers",
                ['query' => ['api_key' => $apiKey]]
            );
            $pData = json_decode((string) $providers->getBody(), true);
            $es = $pData['results']['ES']['flatrate'] ?? [];
            if (!is_array($es)) {
                return [];
            }

            $names = [];
            foreach ($es as $item) {
                $name = trim((string) ($item['provider_name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }

            return array_values(array_unique($names));
        } catch (\Throwable $e) {
            Logger::warning('TMDb streaming lookup failed: ' . $e->getMessage());

            return [];
        }
    }

    private function shortTitle(string $title): string
    {
        $parts = explode('(', $title, 2);

        return trim($parts[0]);
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
