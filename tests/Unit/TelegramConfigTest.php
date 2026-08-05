<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TelegramConfig;
use Tests\TestCase;

final class TelegramConfigTest extends TestCase
{
    public function testSandboxRedirectsToSandboxChat(): void
    {
        $targets = TelegramConfig::resolveTargets('999888777', [
            'sandbox_enabled' => true,
            'sandbox_chat_id' => '2023182976',
            'sandbox_copy_real' => false,
        ]);

        $this->assertSame(['2023182976'], $targets);
    }

    public function testSandboxCanCopyRealUser(): void
    {
        $targets = TelegramConfig::resolveTargets('999888777', [
            'sandbox_enabled' => true,
            'sandbox_chat_id' => '2023182976',
            'sandbox_copy_real' => true,
        ]);

        $this->assertSame(['2023182976', '999888777'], $targets);
    }

    public function testWithoutSandboxUsesIntendedChat(): void
    {
        $targets = TelegramConfig::resolveTargets('999888777', [
            'sandbox_enabled' => false,
            'sandbox_chat_id' => '2023182976',
            'sandbox_copy_real' => false,
        ]);

        $this->assertSame(['999888777'], $targets);
    }

    public function testSandboxWithoutChatIdFallsBackToIntended(): void
    {
        $targets = TelegramConfig::resolveTargets('999888777', [
            'sandbox_enabled' => true,
            'sandbox_chat_id' => '',
            'sandbox_copy_real' => false,
        ]);

        $this->assertSame(['999888777'], $targets);
    }
}
