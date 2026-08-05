<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ServerSyncService;
use Tests\TestCase;

final class ServerSyncUserMergeTest extends TestCase
{
    public function test_does_not_wipe_local_email_when_remote_email_empty(): void
    {
        $payload = ServerSyncService::mergeRemoteIntoLocalUser(
            ['email' => null, 'thumb' => null],
            ['email' => 'keep@example.com', 'display_name' => 'Cliente VIP', 'avatar' => null],
            'plexuser'
        );

        $this->assertSame('plexuser', $payload['username']);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('display_name', $payload);
        $this->assertArrayNotHasKey('avatar', $payload);
    }

    public function test_updates_email_only_when_remote_has_value(): void
    {
        $payload = ServerSyncService::mergeRemoteIntoLocalUser(
            ['email' => 'new@example.com', 'thumb' => 'https://img/x.png'],
            ['email' => 'old@example.com', 'display_name' => '', 'avatar' => null],
            'user1'
        );

        $this->assertSame('new@example.com', $payload['email']);
        $this->assertSame('user1', $payload['display_name']);
        $this->assertSame('https://img/x.png', $payload['avatar']);
    }

    public function test_jellyfin_null_email_pattern_preserves_local(): void
    {
        // JellyfinService::getUsers() siempre envía email => null
        $payload = ServerSyncService::mergeRemoteIntoLocalUser(
            ['email' => null, 'thumb' => null],
            ['email' => 'from-import@example.com', 'display_name' => 'Nombre', 'avatar' => 'a.png'],
            'jfuser'
        );

        $this->assertArrayNotHasKey('email', $payload);
        $this->assertSame('jfuser', $payload['username']);
    }
}
