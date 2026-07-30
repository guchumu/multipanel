<?php

declare(strict_types=1);

use App\Controllers\Api\AuthApiController;
use App\Controllers\Api\DashboardApiController;
use App\Controllers\Api\ServerApiController;
use App\Controllers\Api\MediaUserApiController;
use App\Controllers\Api\GraphQLController;
use App\Controllers\Api\CustomerApiController;
use App\Controllers\Api\InvoiceApiController;
use App\Controllers\Api\EventsApiController;
use App\Controllers\Api\IncomingWebhookController;
use App\Middleware\ApiKeyMiddleware;
use App\Middleware\JwtMiddleware;
use App\Middleware\RateLimitMiddleware;
use Core\Application;

$router = Application::getInstance()->router();

$router->group(['prefix' => '/api/v1', 'middleware' => [RateLimitMiddleware::class]], function ($router) {
    // Public API
    $router->get('/health', [DashboardApiController::class, 'health'], 'api.health');
    $router->get('/events/poll', [EventsApiController::class, 'poll'], 'api.events.poll');
    $router->post('/auth/login', [AuthApiController::class, 'login'], 'api.auth.login');
    $router->post('/auth/refresh', [AuthApiController::class, 'refresh'], 'api.auth.refresh');

    // Incoming webhooks (API key auth)
    $router->group(['middleware' => [ApiKeyMiddleware::class]], function ($router) {
        $router->get('/hooks/status', [IncomingWebhookController::class, 'status'], 'api.hooks.status');
        $router->post('/hooks/{event}', [IncomingWebhookController::class, 'trigger'], 'api.hooks.trigger');
    });

    // GraphQL (public health, auth required for rest via controller)
    $router->post('/graphql', [GraphQLController::class, 'handle'], 'api.graphql');
    $router->get('/graphql/schema', [GraphQLController::class, 'schema'], 'api.graphql.schema');

    // Protected API
    $router->group(['middleware' => [JwtMiddleware::class]], function ($router) {
        $router->get('/auth/me', [AuthApiController::class, 'me'], 'api.auth.me');
        $router->get('/dashboard', [DashboardApiController::class, 'index'], 'api.dashboard');

        // Servers
        $router->get('/servers', [ServerApiController::class, 'index'], 'api.servers.index');
        $router->get('/servers/{uuid}', [ServerApiController::class, 'show'], 'api.servers.show');
        $router->post('/servers', [ServerApiController::class, 'store'], 'api.servers.store');
        $router->delete('/servers/{uuid}', [ServerApiController::class, 'destroy'], 'api.servers.destroy');
        $router->post('/servers/{uuid}/sync', [ServerApiController::class, 'sync'], 'api.servers.sync');

        // Media Users
        $router->get('/users', [MediaUserApiController::class, 'index'], 'api.users.index');
        $router->get('/users/{uuid}', [MediaUserApiController::class, 'show'], 'api.users.show');
        $router->post('/users', [MediaUserApiController::class, 'store'], 'api.users.store');
        $router->patch('/users/{uuid}', [MediaUserApiController::class, 'update'], 'api.users.update');
        $router->delete('/users/{uuid}', [MediaUserApiController::class, 'destroy'], 'api.users.destroy');

        // CRM & Invoices
        $router->get('/customers', [CustomerApiController::class, 'index'], 'api.customers.index');
        $router->get('/customers/{uuid}', [CustomerApiController::class, 'show'], 'api.customers.show');
        $router->get('/invoices', [InvoiceApiController::class, 'index'], 'api.invoices.index');
        $router->get('/invoices/{id}', [InvoiceApiController::class, 'show'], 'api.invoices.show');
    });
});
