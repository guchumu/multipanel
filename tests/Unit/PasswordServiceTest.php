<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PasswordService;
use Tests\TestCase;

final class PasswordServiceTest extends TestCase
{
    private PasswordService $service;

    protected function setUp(): void
    {
        $this->service = new PasswordService();
    }

    public function testHashAndVerify(): void
    {
        $password = 'SecurePass123!';
        $hash = $this->service->hash($password);

        $this->assertNotSame($password, $hash);
        $this->assertTrue($this->service->verify($password, $hash));
        $this->assertFalse($this->service->verify('wrong', $hash));
    }

    public function testGeneratePassword(): void
    {
        $password = $this->service->generate(16);
        $this->assertSame(16, strlen($password));
    }

    public function testValidatePolicy(): void
    {
        $this->assertTrue($this->service->validate('Abcdef12'));
        $this->assertFalse($this->service->validate('short'));
        $this->assertFalse($this->service->validate('alllowercase1'));
    }
}
