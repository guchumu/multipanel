<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * Parses Plex PMS responses (XML or JSON MediaContainer).
 */
final class PlexResponseParser
{
    /**
     * @return array{
     *     ok: bool,
     *     error: ?string,
     *     format: ?string,
     *     xml: ?\SimpleXMLElement,
     *     container: ?array<string, mixed>
     * }
     */
    public static function parseMediaContainer(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return self::fail('Respuesta vacía del servidor Plex.');
        }

        if (stripos($body, '<html') !== false || stripos($body, '<!DOCTYPE html') !== false) {
            return self::fail('El servidor respondió HTML (URL incorrecta, proxy o túnel caído — no es Plex).');
        }

        if ($body[0] === '{' || $body[0] === '[') {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $container = self::extractContainer($json);
                if ($container !== null && self::isPlexContainer($container)) {
                    return [
                        'ok' => true,
                        'error' => null,
                        'format' => 'json',
                        'xml' => null,
                        'container' => $container,
                    ];
                }
            }
        }

        $xmlBody = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlBody);
        libxml_clear_errors();

        if ($xml instanceof \SimpleXMLElement) {
            $container = [
                'machineIdentifier' => (string) ($xml['machineIdentifier'] ?? ''),
                'friendlyName' => (string) ($xml['friendlyName'] ?? ''),
                'version' => (string) ($xml['version'] ?? ''),
                'platform' => (string) ($xml['platform'] ?? ''),
            ];

            if (self::isPlexContainer($container)) {
                return [
                    'ok' => true,
                    'error' => null,
                    'format' => 'xml',
                    'xml' => $xml,
                    'container' => $container,
                ];
            }
        }

        return self::fail('Respuesta no reconocida de Plex (¿token inválido o URL que no apunta al servidor?).');
    }

    /** @param array<string, mixed> $json */
    private static function extractContainer(array $json): ?array
    {
        if (isset($json['MediaContainer']) && is_array($json['MediaContainer'])) {
            return $json['MediaContainer'];
        }

        if (isset($json['machineIdentifier']) || isset($json['friendlyName'])) {
            return $json;
        }

        return null;
    }

    /** @param array<string, mixed> $container */
    private static function isPlexContainer(array $container): bool
    {
        $machineId = (string) ($container['machineIdentifier'] ?? $container['machine_identifier'] ?? '');
        $name = (string) ($container['friendlyName'] ?? $container['friendly_name'] ?? $container['name'] ?? '');

        return $machineId !== '' || $name !== '';
    }

    /** @return array{ok: false, error: string, format: null, xml: null, container: null} */
    private static function fail(string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'format' => null,
            'xml' => null,
            'container' => null,
        ];
    }
}
