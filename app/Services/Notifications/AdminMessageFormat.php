<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Texto legible para avisos admin (Telegram, WhatsApp, email).
 */
final class AdminMessageFormat
{
    /** @param list<string> $sections */
    public static function compose(array $sections): string
    {
        $parts = [];
        foreach ($sections as $section) {
            $section = trim($section);
            if ($section !== '') {
                $parts[] = $section;
            }
        }

        return implode("\n\n", $parts);
    }

    public static function title(string $text): string
    {
        return '— ' . trim($text) . ' —';
    }

    public static function label(string $label, string $value): string
    {
        $label = rtrim(trim($label), ':') . ':';
        $value = trim($value);
        if ($value === '') {
            return $label;
        }
        if (str_contains($value, "\n")) {
            return $label . "\n" . $value;
        }

        return $label . ' ' . $value;
    }

    /** @param list<string> $lines */
    public static function block(string $heading, array $lines): string
    {
        $body = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $body[] = $line;
            }
        }
        if ($body === []) {
            return trim($heading);
        }

        $heading = trim($heading);

        return $heading . "\n" . implode("\n", $body);
    }

    /** @param list<string> $items */
    public static function bullets(array $items): string
    {
        $lines = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $lines[] = '• ' . $item;
        }

        return implode("\n", $lines);
    }

    public static function normalizeSpacing(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return preg_replace("/\n{3,}/", "\n\n", trim($text)) ?? trim($text);
    }

    /** Convierte saltos y etiquetas "Clave: valor" a HTML legible en Telegram. */
    public static function toTelegramHtml(string $message): string
    {
        $message = self::normalizeSpacing($message);
        $lines = explode("\n", $message);
        $html = [];

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $html[] = '';
                continue;
            }

            if (preg_match('/^—.+—$/u', $trim)) {
                $html[] = '<b>' . TelegramChannel::escapeHtml($trim) . '</b>';
                continue;
            }

            if (preg_match('/^([^:\n]{2,52}):\s*(.+)$/u', $trim, $m)) {
                $label = trim($m[1]) . ':';
                $html[] = '<b>' . TelegramChannel::escapeHtml($label) . '</b> '
                    . TelegramChannel::linkifyHtml($m[2]);
                continue;
            }

            if (preg_match('/^([^:\n]{2,52}):$/u', $trim)) {
                $html[] = '<b>' . TelegramChannel::escapeHtml($trim) . '</b>';
                continue;
            }

            if (str_starts_with($trim, '• ')) {
                $html[] = TelegramChannel::linkifyHtml($line);
                continue;
            }

            $html[] = TelegramChannel::linkifyHtml($line);
        }

        return implode("\n", $html);
    }
}
