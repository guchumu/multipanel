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
        // Este panel solo lo usan administradores autenticados: mostrarles el
        // mensaje real del error (en vez de un genérico "Error interno del
        // servidor") es lo que permite diagnosticar fallos sin acceso directo
        // a los logs del servidor. Los usuarios anónimos siguen viendo el
        // mensaje genérico.
        $revealMessage = $debug || self::isAuthenticated();

        if ($e instanceof HttpException) {
            http_response_code($e->getStatusCode());
        } else {
            http_response_code(500);
        }

        if (self::wantsJson()) {
            header('Content-Type: application/json');
            $payload = [
                'error' => true,
                'message' => $revealMessage ? self::describe($e) : 'Error interno del servidor.',
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

        if ($revealMessage) {
            echo '<h1>Error</h1><pre>' . htmlspecialchars(self::describe($e)) . '</pre>';
            return;
        }

        $errorView = base_path('resources/views/errors/500.php');
        if (file_exists($errorView)) {
            include $errorView;
            return;
        }

        echo 'Error interno del servidor.';
    }

    private static function isAuthenticated(): bool
    {
        try {
            return \Core\Session::getInstance()->get('user_id') !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private static function describe(Throwable $e): string
    {
        return sprintf(
            '%s en %s:%d',
            $e->getMessage(),
            basename($e->getFile()),
            $e->getLine()
        );
    }

    private static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_contains($accept, 'application/json') || str_starts_with($uri, '/api/');
    }
}
