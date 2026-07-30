<?php

declare(strict_types=1);

namespace Core;

/**
 * HTTP Request wrapper.
 */
final class Request
{
    private function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array $query,
        private readonly array $post,
        private readonly array $server,
        private readonly array $headers,
        private readonly string $body,
        private readonly array $files,
        private readonly array $cookies,
    ) {
    }

    public static function capture(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri = rtrim($uri, '/') ?: '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }

        $body = file_get_contents('php://input') ?: '';

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper((string) $_POST['_method']);
        }

        return new self(
            $method,
            $uri,
            $_GET,
            $_POST,
            $_SERVER,
            $headers,
            $body,
            $_FILES,
            $_COOKIE,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();

        if (is_array($json) && array_key_exists($key, $json)) {
            return $json[$key];
        }

        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        $json = $this->json();
        if (is_array($json)) {
            return array_merge($this->query, $json);
        }

        return array_merge($this->query, $this->post);
    }

    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }

        $contentType = $this->header('Content-Type', '');
        if (!str_contains($contentType, 'application/json')) {
            return null;
        }

        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[$name] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization', '');
        if (preg_match('/Bearer\s+(\S+)/i', $auth, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->uri, '/api/');
    }

    public function ip(): string
    {
        return $this->server['HTTP_X_FORWARDED_FOR']
            ?? $this->server['REMOTE_ADDR']
            ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }
}
