<?php

declare(strict_types=1);

namespace Core;

/**
 * HTTP Response wrapper.
 */
final class Response
{
    public function __construct(
        private mixed $content = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public static function download(string $filePath, string $filename): self
    {
        return new self(
            (string) file_get_contents($filePath),
            200,
            [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Length' => (string) filesize($filePath),
            ]
        );
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        if ($this->content !== '') {
            echo $this->content;
        }
    }
}
