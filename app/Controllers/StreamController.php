<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\StatsService;
use App\Repositories\ServerRepository;
use Core\Controller;
use Core\Request;

/**
 * Server-Sent Events for real-time dashboard.
 */
class StreamController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private StatsService $stats = new StatsService(),
        private ServerRepository $servers = new ServerRepository(),
    ) {
    }

    public function events(Request $request): never
    {
        if (!$this->auth->check()) {
            http_response_code(401);
            exit;
        }

        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        // Sin esto, este stream retiene el lock de sesión hasta 5 minutos y
        // bloquea la carga de cualquier otra página del mismo navegador.
        \Core\Session::getInstance()->close();

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', 'off');
        if (ob_get_level()) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        $lastEventCheck = date('c');

        for ($i = 0; $i < 60; $i++) {
            if (connection_aborted()) {
                break;
            }

            $payload = [
                'stats' => $this->stats->getDashboardStats($tenantId),
                'servers' => array_map(fn ($s) => [
                    'uuid' => $s->uuid,
                    'name' => $s->name,
                    'status' => $s->status,
                    'sessions' => (int) $s->active_sessions,
                ], $this->servers->allByTenant($tenantId)),
                'hub' => \App\Services\EventHub::snapshot($tenantId),
                'timestamp' => date('c'),
            ];

            echo "event: dashboard\n";
            echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";

            $events = \App\Services\EventHub::since($lastEventCheck);
            foreach ($events as $ev) {
                echo "event: {$ev['event']}\n";
                echo 'data: ' . json_encode($ev['data'], JSON_UNESCAPED_UNICODE) . "\n\n";
            }
            $lastEventCheck = date('c');
            flush();
            sleep(5);
        }

        exit;
    }
}
