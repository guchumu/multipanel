<?php

declare(strict_types=1);

namespace Core;

use Core\Exceptions\HttpException;
use Core\Exceptions\NotFoundException;

/**
 * HTTP Router with named routes and middleware support.
 */
final class Router
{
    /** @var array<string, array{method: string, path: string, handler: callable|array, middleware: array}> */
    private static array $routes = [];

    /** @var array<string, string> */
    private static array $namedRoutes = [];

    /** @var array<int, class-string> */
    private array $globalMiddleware = [];

    public function useMiddleware(string $middlewareClass): void
    {
        $this->globalMiddleware[] = $middlewareClass;
    }

    public function get(string $path, callable|array $handler, ?string $name = null, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $name, $middleware);
    }

    public function post(string $path, callable|array $handler, ?string $name = null, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $name, $middleware);
    }

    public function put(string $path, callable|array $handler, ?string $name = null, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $name, $middleware);
    }

    public function patch(string $path, callable|array $handler, ?string $name = null, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $name, $middleware);
    }

    public function delete(string $path, callable|array $handler, ?string $name = null, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $name, $middleware);
    }

    public function group(array $options, callable $callback): void
    {
        $prefix = $options['prefix'] ?? '';
        $middleware = $options['middleware'] ?? [];

        $previousPrefix = $this->currentPrefix ?? '';
        $previousMiddleware = $this->currentMiddleware ?? [];

        $this->currentPrefix = $previousPrefix . $prefix;
        $this->currentMiddleware = array_merge($previousMiddleware, (array) $middleware);

        $callback($this);

        $this->currentPrefix = $previousPrefix;
        $this->currentMiddleware = $previousMiddleware;
    }

    private ?string $currentPrefix = '';

    /** @var array<int, class-string> */
    private array $currentMiddleware = [];

    private function add(string $method, string $path, callable|array $handler, ?string $name, array $middleware): void
    {
        $fullPath = ($this->currentPrefix ?? '') . $path;
        $fullPath = rtrim($fullPath, '/') ?: '/';
        $allMiddleware = array_merge($this->currentMiddleware ?? [], $middleware);

        $key = $method . ':' . $fullPath;
        self::$routes[$key] = [
            'method' => $method,
            'path' => $fullPath,
            'handler' => $handler,
            'middleware' => $allMiddleware,
        ];

        if ($name !== null) {
            self::$namedRoutes[$name] = $fullPath;
        }
    }

    public static function url(string $name, array $params = []): string
    {
        $path = self::$namedRoutes[$name] ?? $name;

        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', (string) $value, $path);
        }

        return rtrim(config('app.url', ''), '/') . $path;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $uri = $request->uri();

        foreach (self::$routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $pattern = $this->pathToRegex($route['path']);
            if (!preg_match($pattern, $uri, $matches)) {
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            $handler = $this->resolveHandler($route['handler'], $params);
            $middleware = array_merge($this->globalMiddleware, $route['middleware']);

            $next = function (Request $req) use ($handler) {
                return $this->invokeHandler($handler, $req);
            };

            foreach (array_reverse($middleware) as $middlewareClass) {
                $instance = new $middlewareClass();
                $next = fn (Request $req) => $instance->handle($req, $next);
            }

            $result = $next($request);

            return $this->toResponse($result);
        }

        throw new NotFoundException("Route not found: {$method} {$uri}");
    }

    private function pathToRegex(string $path): string
    {
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    private function resolveHandler(callable|array $handler, array $params): callable
    {
        if (is_callable($handler)) {
            return fn (Request $req) => $handler($req, ...array_values($params));
        }

        [$class, $method] = $handler;
        return fn (Request $req) => (new $class())->$method($req, ...array_values($params));
    }

    private function invokeHandler(callable $handler, Request $request): mixed
    {
        return $handler($request);
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::html((string) $result);
    }
}
