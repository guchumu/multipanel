<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Services\Media\JellyfinService;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexConnectionResolver;
use App\Services\Media\PlexService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Detailed connection diagnostics for media servers.
 */
final class ServerConnectionDebugService
{
    public function __construct(
        private PlexConnectionResolver $plexResolver = new PlexConnectionResolver(),
    ) {
    }

    /** @return array<string, mixed> */
    public function diagnose(Server $server): array
    {
        $debug = [
            'checked_at' => now()->format('Y-m-d H:i:s'),
            'server_id' => (int) $server->id,
            'server_name' => (string) $server->name,
            'type' => (string) $server->type,
            'status' => (string) $server->status,
            'configured_url' => $server->fullUrl(),
            'machine_id' => (string) ($server->machine_id ?? ''),
            'has_token' => trim((string) ($server->token ?? '')) !== '',
            'has_api_key' => trim((string) ($server->api_key ?? '')) !== '',
            'last_error' => (string) ($server->last_error ?? ''),
            'probes' => [],
            'plex_tv' => [],
            'suggestions' => [],
            'connected' => false,
        ];

        if ($server->isPlex()) {
            return array_merge($debug, $this->diagnosePlex($server));
        }

        return array_merge($debug, $this->diagnoseJellyfin($server));
    }

    /** @return array<string, mixed> */
    private function diagnosePlex(Server $server): array
    {
        $token = trim((string) ($server->token ?? ''));
        $resolverDebug = $this->plexResolver->diagnose($server);
        $suggestions = $this->buildSuggestions($server, $resolverDebug);

        $connected = false;
        $workingEndpoint = null;

        foreach ($resolverDebug['probes'] as $probe) {
            if ($probe['ok']) {
                $connected = true;
                $workingEndpoint = $probe['url'];
                break;
            }
        }

        if ($connected) {
            try {
                $media = MediaServerFactory::make($server);
                $connected = $media->testConnection();
                if (!$connected && $media instanceof PlexService) {
                    $resolverDebug['final_error'] = $media->getLastError();
                }
            } catch (\Throwable $e) {
                $connected = false;
                $resolverDebug['final_error'] = $e->getMessage();
            }
        }

        return [
            'probes' => $resolverDebug['probes'],
            'plex_tv' => $resolverDebug['plex_tv'],
            'working_endpoint' => $workingEndpoint,
            'connected' => $connected,
            'suggestions' => $suggestions,
            'final_error' => $resolverDebug['final_error'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function diagnoseJellyfin(Server $server): array
    {
        $url = $server->fullUrl();
        $probes = [];
        $connected = false;
        $finalError = null;

        try {
            $client = new Client(['timeout' => 12, 'connect_timeout' => 8, 'verify' => false]);
            $headers = [];
            if ($server->api_key) {
                $headers['Authorization'] = 'MediaBrowser Token="' . $server->api_key . '"';
            }

            $response = $client->get($url . '/System/Info/Public', ['headers' => $headers]);
            $data = json_decode($response->getBody()->getContents(), true);
            $connected = is_array($data);
            $probes[] = [
                'url' => $url . '/System/Info/Public',
                'ok' => $connected,
                'error' => $connected ? null : 'Respuesta inválida',
                'latency_ms' => null,
            ];
        } catch (GuzzleException $e) {
            $finalError = $e->getMessage();
            $probes[] = [
                'url' => $url . '/System/Info/Public',
                'ok' => false,
                'error' => $e->getMessage(),
                'latency_ms' => null,
            ];
        }

        $suggestions = [];
        if (str_contains((string) $server->url, '192.168.') || str_contains((string) $server->url, '10.')) {
            $suggestions[] = 'La URL parece ser una IP local. Desde un VPS remoto no será alcanzable.';
        }
        if (!$server->api_key) {
            $suggestions[] = 'Falta la API Key de Jellyfin.';
        }
        if (!$connected && $finalError) {
            $suggestions[] = 'Comprueba que el puerto ' . $server->port . ' esté abierto y accesible desde internet.';
        }

        return [
            'probes' => $probes,
            'plex_tv' => [],
            'working_endpoint' => $connected ? $url : null,
            'connected' => $connected,
            'suggestions' => $suggestions,
            'final_error' => $finalError,
        ];
    }

    /** @param array<string, mixed> $resolverDebug */
    private function buildSuggestions(Server $server, array $resolverDebug): array
    {
        $suggestions = [];

        if (trim((string) ($server->token ?? '')) === '') {
            $suggestions[] = 'No hay token Plex configurado. Usa autodetectar o pega el token manualmente.';
        }

        if (trim((string) ($server->machine_id ?? '')) === '') {
            $suggestions[] = 'Falta machine_id. Vuelve a autodetectar el servidor o sincroniza tras una conexión exitosa.';
        }

        if (str_contains((string) $server->url, '192.168.') || str_contains((string) $server->url, '10.')) {
            $suggestions[] = 'URL local detectada (' . $server->url . '). Usa el dominio/túnel público (ej. mooo.com:32400).';
        }

        $allLocal = true;
        foreach ($resolverDebug['probes'] as $probe) {
            if (!str_contains($probe['url'], '192.168.') && !str_contains($probe['url'], '10.')) {
                $allLocal = false;
            }
        }
        if ($allLocal && $resolverDebug['probes'] !== []) {
            $suggestions[] = 'Solo se encontraron conexiones locales en plex.tv. El panel remoto necesita una URL pública o relay.';
        }

        $hasOk = false;
        foreach ($resolverDebug['probes'] as $probe) {
            if ($probe['ok']) {
                $hasOk = true;
                break;
            }
        }

        if (!$hasOk && ($resolverDebug['probes'] !== [])) {
            $suggestions[] = 'Ninguna URL respondió. Verifica túnel activo, puerto forwarding y token válido.';
            $suggestions[] = 'Prueba acceder manualmente a la URL configurada desde el navegador con ?X-Plex-Token=TU_TOKEN';
        }

        if (($resolverDebug['plex_tv']['resources_found'] ?? 0) === 0 && trim((string) ($server->token ?? '')) !== '') {
            $suggestions[] = 'plex.tv no devolvió recursos con este token. El token puede estar caducado.';
        }

        return array_values(array_unique($suggestions));
    }

    public function persistDebug(Server $server, array $debug): void
    {
        $settings = [];
        if (!empty($server->settings)) {
            $decoded = json_decode((string) $server->settings, true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        $settings['connection_debug'] = $debug;
        $server->settings = json_encode($settings, JSON_UNESCAPED_UNICODE);
        $server->save();
    }

    /** @return array<string, mixed>|null */
    public function loadDebug(Server $server): ?array
    {
        if (empty($server->settings)) {
            return null;
        }

        $decoded = json_decode((string) $server->settings, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded['connection_debug'] ?? null;
    }
}
