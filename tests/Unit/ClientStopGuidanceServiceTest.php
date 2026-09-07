<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ClientStopGuidanceService;
use PHPUnit\Framework\TestCase;

final class ClientStopGuidanceServiceTest extends TestCase
{
    public function testMessagesMentionSupportAndStayUnderLimit(): void
    {
        $messages = [
            ClientStopGuidanceService::forSalvableTranscode(),
            ClientStopGuidanceService::forHomeLimit(),
            ClientStopGuidanceService::forAwayLimit(),
            ClientStopGuidanceService::forGenericStreamLimit(),
        ];

        foreach ($messages as $message) {
            $this->assertNotSame('', trim($message));
            $this->assertLessThanOrEqual(500, mb_strlen($message));
            $this->assertStringContainsStringIgnoringCase('soporte', $message);
        }

        $this->assertStringContainsString('Original', ClientStopGuidanceService::forSalvableTranscode());
        $this->assertStringContainsString('casa', ClientStopGuidanceService::forHomeLimit());
        $this->assertStringContainsString('fuera', ClientStopGuidanceService::forAwayLimit());
    }
}
