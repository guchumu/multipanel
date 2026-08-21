<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ServerPlacementService;
use PHPUnit\Framework\TestCase;

final class ServerPlacementServiceTest extends TestCase
{
    public function testKeepsExistingServerEvenIfOverQuota(): void
    {
        $servers = [
            ['id' => 1, 'type' => 'plex', 'is_default' => 1, 'user_quota' => 2, 'used' => 2],
            ['id' => 2, 'type' => 'jellyfin', 'is_default' => 1, 'user_quota' => 10, 'used' => 0],
        ];

        $picked = ServerPlacementService::pick($servers, 'plex', 1, 1);

        $this->assertTrue($picked['ok']);
        $this->assertSame(1, $picked['server_id']);
    }

    public function testNewUserGoesToDefaultWhenThereIsRoom(): void
    {
        $servers = [
            ['id' => 5, 'type' => 'plex', 'is_default' => 0, 'user_quota' => 50, 'used' => 0],
            ['id' => 3, 'type' => 'plex', 'is_default' => 1, 'user_quota' => 10, 'used' => 4],
        ];

        $picked = ServerPlacementService::pick($servers, 'plex', 0, 1);

        $this->assertTrue($picked['ok']);
        $this->assertSame(3, $picked['server_id']);
    }

    public function testOverflowsToNextPlexWhenDefaultIsFull(): void
    {
        $servers = [
            ['id' => 3, 'type' => 'plex', 'is_default' => 1, 'user_quota' => 2, 'used' => 2],
            ['id' => 8, 'type' => 'plex', 'is_default' => 0, 'user_quota' => 5, 'used' => 1],
        ];

        $picked = ServerPlacementService::pick($servers, 'plex', 0, 1);

        $this->assertTrue($picked['ok']);
        $this->assertSame(8, $picked['server_id']);
    }

    public function testFailsWhenAllOfTypeAreFull(): void
    {
        $servers = [
            ['id' => 3, 'type' => 'plex', 'is_default' => 1, 'user_quota' => 2, 'used' => 2],
        ];

        $picked = ServerPlacementService::pick($servers, 'plex', 0, 1);

        $this->assertFalse($picked['ok']);
        $this->assertNull($picked['server_id']);
    }

    public function testUnlimitedQuotaAlwaysFits(): void
    {
        $servers = [
            ['id' => 1, 'type' => 'jellyfin', 'is_default' => 1, 'user_quota' => 0, 'used' => 999],
        ];

        $picked = ServerPlacementService::pick($servers, 'jellyfin', 0, 3);

        $this->assertTrue($picked['ok']);
        $this->assertSame(1, $picked['server_id']);
    }
}
