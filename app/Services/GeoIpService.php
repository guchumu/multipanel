<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Media\SessionClientIp;
use Core\Cache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Resuelve ISO-2 de país a partir de IP pública. IPs LAN se omiten.
 */
final class GeoIpService
{
    /** @var array<string, string> */
    private const NAMES_ES = [
        'AD' => 'Andorra', 'AE' => 'Emiratos Árabes', 'AR' => 'Argentina', 'AT' => 'Austria',
        'AU' => 'Australia', 'BE' => 'Bélgica', 'BO' => 'Bolivia', 'BR' => 'Brasil',
        'CA' => 'Canadá', 'CH' => 'Suiza', 'CL' => 'Chile', 'CN' => 'China',
        'CO' => 'Colombia', 'CR' => 'Costa Rica', 'CU' => 'Cuba', 'CZ' => 'Chequia',
        'DE' => 'Alemania', 'DK' => 'Dinamarca', 'DO' => 'Rep. Dominicana', 'EC' => 'Ecuador',
        'EE' => 'Estonia', 'EG' => 'Egipto', 'ES' => 'España', 'FI' => 'Finlandia',
        'FR' => 'Francia', 'GB' => 'Reino Unido', 'GR' => 'Grecia', 'GT' => 'Guatemala',
        'HN' => 'Honduras', 'HR' => 'Croacia', 'HU' => 'Hungría', 'IE' => 'Irlanda',
        'IL' => 'Israel', 'IN' => 'India', 'IT' => 'Italia', 'JP' => 'Japón',
        'KR' => 'Corea del Sur', 'MA' => 'Marruecos', 'MX' => 'México', 'NL' => 'Países Bajos',
        'NO' => 'Noruega', 'NZ' => 'Nueva Zelanda', 'PA' => 'Panamá', 'PE' => 'Perú',
        'PL' => 'Polonia', 'PT' => 'Portugal', 'PY' => 'Paraguay', 'RO' => 'Rumanía',
        'RU' => 'Rusia', 'SE' => 'Suecia', 'SV' => 'El Salvador', 'TR' => 'Turquía',
        'UA' => 'Ucrania', 'US' => 'Estados Unidos', 'UY' => 'Uruguay', 'VE' => 'Venezuela',
    ];

    public function __construct(private ?Client $http = null)
    {
        $this->http ??= new Client([
            'timeout' => 2.5,
            'connect_timeout' => 1.5,
            'http_errors' => false,
        ]);
    }

    public function countryCode(string $ip): ?string
    {
        $ip = SessionClientIp::normalize($ip);
        if ($ip === '' || SessionClientIp::isPrivate($ip)) {
            return null;
        }

        $cached = Cache::get('geoip:' . $ip);
        if (is_string($cached)) {
            $cached = strtoupper($cached);
            return $cached === '' ? null : $cached;
        }

        $code = $this->lookup($ip);
        Cache::set('geoip:' . $ip, $code ?? '', $code ? 86400 * 30 : 3600);

        return $code;
    }

    public static function countryName(?string $code): string
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return 'Desconocido';
        }

        return self::NAMES_ES[$code] ?? $code;
    }

    private function lookup(string $ip): ?string
    {
        try {
            $res = $this->http->get('https://ipwho.is/' . rawurlencode($ip), [
                'query' => ['fields' => 'success,country_code'],
            ]);
            $body = json_decode((string) $res->getBody(), true);
            if (!is_array($body) || empty($body['success'])) {
                return null;
            }
            $code = strtoupper(trim((string) ($body['country_code'] ?? '')));
            return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
        } catch (GuzzleException) {
            return null;
        }
    }
}
