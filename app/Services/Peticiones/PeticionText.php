<?php

declare(strict_types=1);

namespace App\Services\Peticiones;

/**
 * Títulos del panel legacy: UTF-8 leído como latin1 (LÃ¡mpara) y entidades HTML (&amp;).
 */
final class PeticionText
{
    public static function repair(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        $text = self::decodeEntities($text);
        $text = self::fixMojibake($text);
        $text = self::applySpanishMap($text);

        return trim($text);
    }

    public static function needsRepair(string $text): bool
    {
        return self::repair($text) !== trim($text);
    }

    private static function decodeEntities(string $text): string
    {
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }

        return $text;
    }

    /**
     * "LÃ¡mpara" / "espaÃ±ol" → "Lámpara" / "español"
     * (UTF-8 original interpretado como Windows-1252 y vuelto a UTF-8).
     */
    private static function fixMojibake(string $text): string
    {
        for ($i = 0; $i < 2; $i++) {
            if (!self::looksLikeMojibake($text)) {
                break;
            }
            $bytes = self::utf8ToWin1252($text);
            if ($bytes === false || $bytes === '' || $bytes === $text) {
                break;
            }
            if (!mb_check_encoding($bytes, 'UTF-8')) {
                break;
            }
            if (self::looksLikeMojibake($bytes) && !self::looksBetter($bytes, $text)) {
                break;
            }
            $text = $bytes;
        }

        return $text;
    }

    public static function looksLikeMojibake(string $text): bool
    {
        return preg_match('/Ã.|Â.|â€/u', $text) === 1;
    }

    private static function looksBetter(string $candidate, string $original): bool
    {
        $before = preg_match_all('/Ã.|Â./u', $original) ?: 0;
        $after = preg_match_all('/Ã.|Â./u', $candidate) ?: 0;

        return $after < $before;
    }

    private static function utf8ToWin1252(string $text): string|false
    {
        if (function_exists('mb_convert_encoding')) {
            foreach (['Windows-1252', 'ISO-8859-1'] as $enc) {
                $out = @mb_convert_encoding($text, $enc, 'UTF-8');
                if (is_string($out) && $out !== '') {
                    return $out;
                }
            }
        }
        if (function_exists('iconv')) {
            $out = @iconv('UTF-8', 'Windows-1252//IGNORE', $text);
            if (is_string($out) && $out !== '') {
                return $out;
            }
        }

        return false;
    }

    /**
     * Pares típicos UTF-8 interpretado como latin1, y la variante «visual» (Ã­, Ã±).
     */
    private static function applySpanishMap(string $text): string
    {
        return strtr($text, [
            "\u{00C3}\u{00A1}" => 'á',
            "\u{00C3}\u{00A9}" => 'é',
            "\u{00C3}\u{00AD}" => 'í',
            "\u{00C3}\u{00ED}" => 'í',
            "\u{00C3}\u{00B3}" => 'ó',
            "\u{00C3}\u{00BA}" => 'ú',
            "\u{00C3}\u{00B1}" => 'ñ',
            "\u{00C3}\u{00F1}" => 'ñ',
            "\u{00C3}\u{0081}" => 'Á',
            "\u{00C3}\u{0089}" => 'É',
            "\u{00C3}\u{008D}" => 'Í',
            "\u{00C3}\u{0093}" => 'Ó',
            "\u{00C3}\u{009A}" => 'Ú',
            "\u{00C3}\u{0091}" => 'Ñ',
            "\u{00C3}\u{00BC}" => 'ü',
            "\u{00C3}\u{009C}" => 'Ü',
            "\u{00C2}\u{00BF}" => '¿',
            "\u{00C2}\u{00A1}" => '¡',
            'Ã¡' => 'á',
            'Ã©' => 'é',
            'Ã­' => 'í',
            'Ã³' => 'ó',
            'Ãº' => 'ú',
            'Ã±' => 'ñ',
            'Ã‘' => 'Ñ',
            'Â¿' => '¿',
            'Â¡' => '¡',
        ]);
    }
}
