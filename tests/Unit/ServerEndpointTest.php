<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Media\ServerEndpoint;
use PHPUnit\Framework\TestCase;

final class ServerEndpointTest extends TestCase
{
    public function testPreferCustomHostnameOverPlexDirect(): void
    {
        $this->assertTrue(ServerEndpoint::shouldPreferCurrentHost('lunasea.mooo.com', [
            'url' => '79-116-40-195.680fc273a3314c4e8e28f3919866206e.plex.direct',
            'port' => 24591,
            'ssl' => false,
        ]));
    }

    public function testPreferCustomHostnameOverIp(): void
    {
        $this->assertTrue(ServerEndpoint::shouldPreferCurrentHost('lunasea.mooo.com', [
            'url' => '79.116.40.195',
            'port' => 24591,
            'ssl' => false,
        ]));
    }
}
