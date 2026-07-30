<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LicenseService;
use Tests\TestCase;

final class LicenseServiceTest extends TestCase
{
    public function testGenerateAndValidateKey(): void
    {
        $service = new LicenseService();
        $key = $service->generateKey('enterprise', date('Y-m-d', strtotime('+1 year')));

        $this->assertNotEmpty($key);
        $this->assertTrue($service->validateKey($key));
        $this->assertFalse($service->validateKey('invalid-key'));
    }

    public function testExpiredKeyIsInvalid(): void
    {
        $service = new LicenseService();
        $key = $service->generateKey('basic', date('Y-m-d', strtotime('-1 day')));

        $this->assertFalse($service->validateKey($key));
    }

    public function testIsValidWithoutLicense(): void
    {
        $service = new LicenseService();
        $this->assertTrue($service->isValid());
    }
}
