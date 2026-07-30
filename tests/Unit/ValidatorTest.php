<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Validator;
use Core\Exceptions\ValidationException;
use Tests\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredField(): void
    {
        $this->expectException(ValidationException::class);
        Validator::make([], ['email' => 'required']);
    }

    public function testValidEmail(): void
    {
        $result = Validator::make(
            ['email' => 'test@example.com'],
            ['email' => 'required|email']
        );
        $this->assertSame('test@example.com', $result['email']);
    }

    public function testInvalidEmail(): void
    {
        $this->expectException(ValidationException::class);
        Validator::make(['email' => 'not-an-email'], ['email' => 'required|email']);
    }

    public function testMinLength(): void
    {
        $this->expectException(ValidationException::class);
        Validator::make(['password' => 'abc'], ['password' => 'required|min:8']);
    }
}
