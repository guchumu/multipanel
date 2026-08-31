<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use DateTimeImmutable;
use DateTimeZone;

/**
 * WhatsApp muestra en la notificación (pantalla bloqueada) solo la primera línea.
 * Por eso el aviso empieza por icono + título + hora.
 */
final class WhatsAppAdminText
{
    public static function wrap(string $title, string $message, string $kind = 'alert'): string
    {
        $when = self::nowMadrid();
        $icon = match ($kind) {
            'ok', 'created' => '✅',
            'renewed' => '🔄',
            'digest' => '📋',
            'cut' => '✂️',
            'sandbox' => '🧪',
            default => '⚠️',
        };
        $title = trim($title) !== '' ? trim($title) : 'Aviso MultiPanel';
        $preview = "{$icon} {$title} · {$when}";
        $bodyMessage = AdminMessageFormat::normalizeSpacing(trim($message));
        $body = '*' . $title . "*\n_" . $when . "_\n\n" . $bodyMessage;

        return $preview . "\n\n" . $body;
    }

    public static function nowMadrid(): string
    {
        try {
            $tz = new DateTimeZone('Europe/Madrid');
        } catch (\Throwable) {
            $tz = new DateTimeZone('UTC');
        }

        return (new DateTimeImmutable('now', $tz))->format('d/m H:i');
    }

    public static function nowMadridLong(): string
    {
        try {
            $tz = new DateTimeZone('Europe/Madrid');
        } catch (\Throwable) {
            $tz = new DateTimeZone('UTC');
        }

        return (new DateTimeImmutable('now', $tz))->format('d/m/Y H:i:s');
    }
}
