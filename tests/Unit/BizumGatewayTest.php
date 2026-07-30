<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\BizumGateway;
use Tests\TestCase;

final class BizumGatewayTest extends TestCase
{
    public function testCreateCheckoutReturnsManualInstructions(): void
    {
        $_ENV['BIZUM_PHONE'] = '600123456';
        putenv('BIZUM_PHONE=600123456');

        $gateway = new BizumGateway();
        $result = $gateway->createCheckoutSession(9.99, 'EUR', ['plan_name' => 'Premium']);

        $this->assertSame('manual', $result['type']);
        $this->assertSame('600123456', $result['instructions']['phone']);
        $this->assertSame(9.99, $result['instructions']['amount']);
        $this->assertNotEmpty($result['reference']);
    }

    public function testMissingPhoneReturnsError(): void
    {
        putenv('BIZUM_PHONE=');
        $_ENV['BIZUM_PHONE'] = '';

        $gateway = new BizumGateway();
        $result = $gateway->createCheckoutSession(9.99, 'EUR');

        $this->assertArrayHasKey('error', $result);

        putenv('BIZUM_PHONE=600123456');
        $_ENV['BIZUM_PHONE'] = '600123456';
    }
}
