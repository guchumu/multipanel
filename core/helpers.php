<?php

declare(strict_types=1);

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        static $config = null;

        if ($config === null) {
            $config = [];
            $path = dirname(__DIR__) . '/config';
            foreach (glob($path . '/*.php') as $file) {
                $config[basename($file, '.php')] = require $file;
            }
        }

        $keys = explode('.', $key);
        $value = $config;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return rtrim(dirname(__DIR__) . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''), '/\\');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return base_path('public' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('view')) {
    function view(string $name, array $data = []): string
    {
        return Core\View::render($name, $data);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $status = 302): never
    {
        header('Location: ' . $url, true, $status);
        exit;
    }
}

if (!function_exists('json_response')) {
    function json_response(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        $session = Core\Session::getInstance();
        $session->start();
        $token = $session->getCsrfToken();
        if ($token === '') {
            // Fallback defensivo si la sesión arrancó sin token.
            $token = bin2hex(random_bytes(32));
            $session->set('_csrf_token', $token);
        }

        return $token;
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(csrf_token()) . '">';
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        return Core\Session::getInstance()->getFlash('old.' . $key, $default);
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        // Ruta relativa al host actual: si APP_URL apunta a localhost (o está
        // mal configurada), los JS/CSS del panel seguirían cargando igual.
        return '/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('route')) {
    function route(string $name, array $params = []): string
    {
        return Core\Router::url($name, $params);
    }
}

if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        return Core\Language::get($key, $replace);
    }
}

if (!function_exists('now')) {
    function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(config('app.timezone', 'UTC')));
    }
}

if (!function_exists('expires_date_input')) {
    /** Valor para `<input type="date">` desde expires_at de BD. */
    function expires_date_input(mixed $expires): string
    {
        return \App\Services\SubscriptionPeriod::formatForInput(
            is_scalar($expires) ? (string) $expires : null
        );
    }
}

if (!function_exists('expires_date_display')) {
    /** Fecha corta para listados (— si no hay). */
    function expires_date_display(mixed $expires): string
    {
        return \App\Services\SubscriptionPeriod::formatForDisplay(
            is_scalar($expires) ? (string) $expires : null
        );
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return htmlspecialchars($value === true ? '1' : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (is_scalar($value)) {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return '';
    }
}

if (!function_exists('esc')) {
    /** Alias de e() para escape HTML en vistas. */
    function esc(mixed $value): string
    {
        return e($value);
    }
}

if (!function_exists('event')) {
    function event(string $name, mixed $payload = null): mixed
    {
        return Core\EventDispatcher::dispatch($name, $payload);
    }
}

if (!function_exists('listen')) {
    function listen(string $name, callable $listener, int $priority = 10): void
    {
        Core\EventDispatcher::listen($name, $listener, $priority);
    }
}

if (!function_exists('days_left')) {
    function days_left(mixed $expiresAt): ?int
    {
        if ($expiresAt === null) {
            return null;
        }

        $expiresAt = trim((string) $expiresAt);
        if ($expiresAt === '' || str_starts_with($expiresAt, '0000-00-00')) {
            return null;
        }

        $tz = new DateTimeZone(config('app.timezone', 'UTC'));
        $today = new DateTimeImmutable('today', $tz);

        try {
            $expires = new DateTimeImmutable(substr($expiresAt, 0, 10), $tz);
        } catch (\Exception) {
            return null;
        }

        return (int) floor(($expires->getTimestamp() - $today->getTimestamp()) / 86400);
    }
}

if (!function_exists('days_left_badge')) {
    /** @return array{label: string, class: string} */
    function days_left_badge(mixed $expiresAt): array
    {
        $days = days_left($expiresAt);

        if ($days === null) {
            return ['label' => 'Sin fecha', 'class' => 'bg-light text-dark border'];
        }

        if ($days < 0) {
            return ['label' => 'Caducó hace ' . abs($days) . 'd', 'class' => 'bg-dark'];
        }

        if ($days === 0) {
            return ['label' => 'Caduca hoy', 'class' => 'bg-danger'];
        }

        if ($days <= 3) {
            return ['label' => "Quedan {$days}d", 'class' => 'bg-danger'];
        }

        if ($days <= 7) {
            return ['label' => "Quedan {$days}d", 'class' => 'bg-warning text-dark'];
        }

        if ($days <= 30) {
            return ['label' => "Quedan {$days}d", 'class' => 'bg-info text-dark'];
        }

        return ['label' => "Quedan {$days}d", 'class' => 'bg-light text-dark border'];
    }
}

if (!function_exists('normalize_telegram_chat_id')) {
    /** Trata vacío y el literal "null" como sin Telegram. */
    function normalize_telegram_chat_id(mixed $value): string
    {
        $tg = trim((string) ($value ?? ''));
        if ($tg === '' || strcasecmp($tg, 'null') === 0) {
            return '';
        }

        return $tg;
    }
}

if (!function_exists('can')) {
    function can(string $permission): bool
    {
        $auth = new \App\Services\AuthService();
        $user = $auth->user();
        if ($user === null) {
            return false;
        }
        return (new \App\Services\PermissionService())->can($user, $permission);
    }
}

if (!function_exists('media_service_badge')) {
    function media_service_badge(?string $type): string
    {
        $type = strtolower(trim((string) $type));
        if ($type === 'plex') {
            return '<span class="badge badge-service-plex">Plex</span>';
        }
        if ($type === 'jellyfin') {
            return '<span class="badge badge-service-jellyfin">Jellyfin</span>';
        }

        return '';
    }
}
