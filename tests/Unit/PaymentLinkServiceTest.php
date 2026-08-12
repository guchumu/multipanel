<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\PaymentLinkService;
use Tests\TestCase;

final class PaymentLinkServiceTest extends TestCase
{
    public function testGenerateCodeHasExpectedLengthAndCharset(): void
    {
        $code = PaymentLinkService::generateCode(8);

        $this->assertSame(8, strlen($code));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $code);
        $this->assertTrue(PaymentLinkService::isValidCode($code));
    }

    public function testRejectsInvalidCodes(): void
    {
        $this->assertFalse(PaymentLinkService::isValidCode(''));
        $this->assertFalse(PaymentLinkService::isValidCode('abc'));
        $this->assertFalse(PaymentLinkService::isValidCode('bad_code'));
        $this->assertFalse(PaymentLinkService::isValidCode('has space'));
        $this->assertTrue(PaymentLinkService::isValidCode('Ab12Xy9Z'));
    }
}
