<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalLoginLinkService;
use Tests\TestCase;

final class PortalLoginLinkServiceTest extends TestCase
{
    public function testGenerateCodeHasExpectedLengthAndCharset(): void
    {
        $code = PortalLoginLinkService::generateCode(22);

        $this->assertSame(22, strlen($code));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $code);
        $this->assertTrue(PortalLoginLinkService::isValidCode($code));
    }

    public function testRejectsInvalidCodes(): void
    {
        $this->assertFalse(PortalLoginLinkService::isValidCode(''));
        $this->assertFalse(PortalLoginLinkService::isValidCode('short'));
        $this->assertFalse(PortalLoginLinkService::isValidCode('has space andmorecharsxx'));
        $this->assertFalse(PortalLoginLinkService::isValidCode('bad_code_with_underscorex'));
        $this->assertTrue(PortalLoginLinkService::isValidCode(str_repeat('A', 16)));
    }

    public function testHashIsStableSha256(): void
    {
        $code = 'AbcdEfGh1234567890Wxyz';
        $this->assertSame(hash('sha256', $code), PortalLoginLinkService::hashCode($code));
        $this->assertSame(64, strlen(PortalLoginLinkService::hashCode($code)));
    }

    public function testPurposeNormalizesToHomeOrPay(): void
    {
        $svc = new PortalLoginLinkService();
        $this->assertSame('pay', $svc->normalizePurpose('pay'));
        $this->assertSame('home', $svc->normalizePurpose('HOME'));
        $this->assertSame('home', $svc->normalizePurpose('hack'));
        $this->assertSame('/portal/subscription', $svc->redirectForPurpose('pay'));
        $this->assertSame('/portal', $svc->redirectForPurpose('home'));
    }
}
