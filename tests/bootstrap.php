<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

require_once dirname(__DIR__) . '/core/helpers.php';

putenv('APP_KEY=test-secret-key-for-unit-tests');
$_ENV['APP_KEY'] = $_ENV['APP_KEY'] ?? 'test-secret-key-for-unit-tests';
$_ENV['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
putenv('AUTO_MIGRATE=false');
$_ENV['AUTO_MIGRATE'] = 'false';

putenv('BIZUM_PHONE=600123456');
$_ENV['BIZUM_PHONE'] = '600123456';
