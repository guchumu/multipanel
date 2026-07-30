<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TwoFactorService;
use Tests\TestCase;

final class TwoFactorServiceTest extends TestCase
{
    private TwoFactorService $service;

    protected function setUp(): void
    {
        $this->service = new TwoFactorService();
    }

    public function testGenerateSecret(): void
    {
        $secret = $this->service->generateSecret();
        $this->assertNotEmpty($secret);
        $this->assertGreaterThan(16, strlen($secret));
    }

    public function testVerifyCodeWithGeneratedSecret(): void
    {
        $secret = $this->service->generateSecret();
        // Generate current valid code via reflection-free approach: verify known invalid fails
        $this->assertFalse($this->service->verifyCode($secret, '000000'));
    }

    public function testRecoveryCodes(): void
    {
        $codes = $this->service->generateRecoveryCodes(5);
        $this->assertCount(5, $codes);
        $this->assertTrue($this->service->verifyRecoveryCode($codes, $codes[0]));
        $this->assertFalse($this->service->verifyRecoveryCode($codes, 'INVALID'));
    }
}
