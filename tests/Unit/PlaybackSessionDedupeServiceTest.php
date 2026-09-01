<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PlaybackSessionDedupeService;
use PHPUnit\Framework\TestCase;

final class PlaybackSessionDedupeServiceTest extends TestCase
{
    public function testClustersPollDuplicatesWithinGap(): void
    {
        $service = new PlaybackSessionDedupeService();
        $rows = [
            $this->row(1, 10, 'Film', '10:00:00', '10:03:00'),
            $this->row(2, 10, 'Film', '10:03:00', '10:06:00'),
            $this->row(3, 10, 'Film', '10:06:00', '10:09:00'),
            $this->row(4, 10, 'Other', '10:00:00', '10:30:00'),
        ];

        $groups = $this->invokeCluster($service, $rows);

        $this->assertCount(2, $groups);
        $this->assertCount(3, $groups[0]);
        $this->assertCount(1, $groups[1]);
    }

    public function testSplitsWhenGapIsTooLarge(): void
    {
        $service = new PlaybackSessionDedupeService();
        $rows = [
            $this->row(1, 10, 'Film', '10:00:00', '10:05:00'),
            $this->row(2, 10, 'Film', '10:20:00', '10:25:00'),
        ];

        $groups = $this->invokeCluster($service, $rows);

        $this->assertCount(2, $groups);
        $this->assertCount(1, $groups[0]);
        $this->assertCount(1, $groups[1]);
    }

    public function testClustersSameTitleDespiteDifferentMediaUserId(): void
    {
        $service = new PlaybackSessionDedupeService();
        $rows = [
            $this->row(1, 10, 'Film', '10:00:00', '10:03:00', 0),
            $this->row(2, 10, 'Film', '10:03:00', '10:06:00', 42),
        ];

        $groups = $this->invokeCluster($service, $rows);

        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups[0]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<list<array<string, mixed>>>
     */
    private function invokeCluster(PlaybackSessionDedupeService $service, array $rows): array
    {
        $method = new \ReflectionMethod($service, 'clusterRows');
        $method->setAccessible(true);

        return $method->invoke($service, $rows);
    }

    private function row(int $id, int $serverId, string $title, string $start, string $end, int $mediaUserId = 5): array
    {
        return [
            'id' => $id,
            'tenant_id' => 1,
            'server_id' => $serverId,
            'media_user_id' => $mediaUserId,
            'external_session_id' => 'hash:' . $id,
            'title' => $title,
            'player' => 'Chrome',
            'started_at' => '2026-08-31 ' . $start,
            'ended_at' => '2026-08-31 ' . $end,
            'duration_seconds' => 180,
            'country' => null,
            'ip_address' => null,
        ];
    }
}
