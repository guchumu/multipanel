<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MetricsService;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * Prometheus metrics endpoint controller.
 */
class MetricsController extends Controller
{
    public function __construct(
        private MetricsService $metrics = new MetricsService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $token = env('METRICS_TOKEN', '');
        if ($token !== '') {
            $provided = $request->input('token', '');
            if ($provided === '') {
                $provided = str_replace('Bearer ', '', (string) ($request->header('Authorization', '') ?? ''));
            }
            if ($provided !== $token) {
                return new Response('Unauthorized', 401);
            }
        }

        return new Response(
            $this->metrics->render(),
            200,
            ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']
        );
    }
}
