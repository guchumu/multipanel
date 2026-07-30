<?php

declare(strict_types=1);

namespace Core;

use Core\Exceptions\HttpException;
use Dotenv\Dotenv;

/**
 * Application singleton bootstrap and lifecycle.
 */
final class Application
{
    private static ?self $instance = null;

    private Router $router;

    private bool $bootstrapped = false;

    private function __construct()
    {
        $this->router = new Router();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $this->loadEnvironment();
        $this->setErrorHandling();
        date_default_timezone_set(config('app.timezone', 'UTC'));

        Session::getInstance()->start();
        Language::setLocale(config('app.locale', 'es'));

        $sessionLocale = Session::getInstance()->get('locale');
        if (is_string($sessionLocale) && in_array($sessionLocale, ['es', 'en'], true)) {
            Language::setLocale($sessionLocale);
        }

        $this->registerRoutes();
        $this->router->useMiddleware(\App\Middleware\SecurityMiddleware::class);
        $this->loadPlugins();
        $this->bootstrapped = true;
    }

    public function run(): void
    {
        $request = Request::capture();
        $response = $this->router->dispatch($request);
        $response->send();
    }

    public function router(): Router
    {
        return $this->router;
    }

    private function loadEnvironment(): void
    {
        $envPath = base_path();
        if (file_exists($envPath . '/.env')) {
            $dotenv = Dotenv::createImmutable($envPath);
            $dotenv->safeLoad();
        }
    }

    private function setErrorHandling(): void
    {
        $debug = config('app.debug', false);

        if ($debug) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
            ini_set('display_errors', '0');
        }
    }

    private function registerRoutes(): void
    {
        $webRoutes = base_path('routes/web.php');
        $apiRoutes = base_path('routes/api.php');

        if (file_exists($webRoutes)) {
            require $webRoutes;
        }

        if (file_exists($apiRoutes)) {
            require $apiRoutes;
        }
    }

    private function loadPlugins(): void
    {
        if (class_exists(\App\Plugins\PluginManager::class)) {
            \App\Plugins\PluginManager::loadActive();
        }
    }
}
