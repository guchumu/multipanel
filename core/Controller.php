<?php

declare(strict_types=1);

namespace Core;

/**
 * Base controller with common helpers.
 */
abstract class Controller
{
    protected function view(string $name, array $data = []): Response
    {
        return Response::html(View::render($name, $data));
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $url): Response
    {
        return Response::redirect($url);
    }

    protected function redirectRoute(string $name, array $params = []): Response
    {
        return Response::redirect(Router::url($name, $params));
    }

    protected function validate(Request $request, array $rules): array
    {
        return Validator::make($request->all(), $rules);
    }
}
