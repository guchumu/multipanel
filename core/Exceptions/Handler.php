<?php

declare(strict_types=1);

namespace Core\Exceptions;

use Core\Logger;
use Throwable;

/**
 * Global exception handler.
 */
final class Handler
{
    public static function handle(Throwable $e): void
    {
        Logger::error($e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $debug = config('app.debug', false);

        if ($e instanceof HttpException) {
            http_response_code($e->getStatusCode());
        } else {
            http_response_code(500);
        }

        if (self::wantsJson()) {
            header('Content-Type: application/json');
            $payload = [
                'error' => true,
                'message' => $debug ? $e->getMessage() : 'Error interno del servidor.',
                'code' => $e instanceof HttpException ? $e->getStatusCode() : 500,
            ];

            if ($e instanceof ValidationException) {
                $payload['errors'] = $e->getErrors();
            }

            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($debug) {
            echo '<h1>Error</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            return;
        }

        $errorView = base_path('resources/views/errors/500.php');
        if (file_exists($errorView)) {
            include $errorView;
            return;
        }

        echo 'Error interno del servidor.';
    }

    private static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_contains($accept, 'application/json') || str_starts_with($uri, '/api/');
    }
}
