<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Media\PlaybackSessionKey;
use PHPUnit\Framework\TestCase;

final class PlaybackSessionKeyTest extends TestCase
{
    public function testUsesPlexSessionIdWhenPresent(): void
    {
        $key = PlaybackSessionKey::forSession([
            'session_id' => 'abc-123',
            'user' => 'pepe',
            'title' => 'Film',
            'player' => 'Chrome',
        ], 7);

        $this->assertSame('sid:7:abc-123', $key);
    }

    public function testLookupKeysIncludeLegacyMd5(): void
    {
        $session = [
            'session_id' => 'abc-123',
            'user' => 'pepe',
            'title' => 'Film',
            'player' => 'Chrome',
        ];

        $keys = PlaybackSessionKey::lookupKeys($session, 7);

        $this->assertContains('sid:7:abc-123', $keys);
        $this->assertContains(PlaybackSessionKey::legacyMd5($session, 7), $keys);
    }

    public function testSyncAndHistoryShareCanonicalKeyWithoutSessionId(): void
    {
        $session = [
            'user' => 'pepe',
            'title' => 'Film',
            'player' => 'Chrome',
        ];

        $this->assertSame(
            PlaybackSessionKey::forSession($session, 3),
            'hash:' . PlaybackSessionKey::legacyMd5($session, 3)
        );
    }
}
