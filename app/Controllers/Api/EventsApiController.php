<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\AuthService;
use App\Services\EventHub;
use Core\Controller;
use Core\RealtimeBroker;
use Core\Request;
use Core\Response;

/**
 * Real-time events polling API (WebSocket fallback).
 */
class EventsApiController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
    ) {
    }

    public function poll(Request $request): Response
    {
        if (!$this->auth->check() && !$request->bearerToken()) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $since = (float) $request->input('since', 0);
        $channel = (string) $request->input('channel', 'dashboard');
        $timeout = min(30, max(1, (int) $request->input('timeout', 15)));

        $deadline = microtime(true) + $timeout;
        do {
            $events = RealtimeBroker::consume($channel, $since);
            if (!empty($events)) {
                return $this->json([
                    'events' => $events,
                    'since' => end($events)['at'] ?? microtime(true),
                    'hub' => EventHub::snapshot(1),
                ]);
            }
            usleep(500000);
        } while (microtime(true) < $deadline);

        return $this->json([
            'events' => [],
            'since' => $since ?: microtime(true),
            'hub' => EventHub::snapshot(1),
        ]);
    }
}
