<?php

declare(strict_types=1);

/**
 * MultiPanel ERP - Application Bootstrap
 *
 * @package MultiPanel
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Core\Application;
use Core\Exceptions\Handler;

$app = Application::getInstance();
$app->bootstrap();

try {
    $app->run();
} catch (Throwable $e) {
    Handler::handle($e);
}
