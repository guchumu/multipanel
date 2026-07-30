<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/core/helpers.php';

putenv('APP_KEY=test-secret-key-for-unit-tests');
$_ENV['APP_KEY'] = 'test-secret-key-for-unit-tests';
$_ENV['APP_ENV'] = 'testing';
putenv('BIZUM_PHONE=600123456');
$_ENV['BIZUM_PHONE'] = '600123456';
