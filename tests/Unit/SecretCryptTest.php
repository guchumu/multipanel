<?php

declare(strict_types=1);

use App\Services\SecretCrypt;
use PHPUnit\Framework\TestCase;

final class SecretCryptTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $crypt = new SecretCrypt();
        $plain = 'JellyPass!234';
        $enc = $crypt->encrypt($plain);

        $this->assertNotSame($plain, $enc);
        $this->assertStringStartsWith('enc:v1:', $enc);
        $this->assertSame($plain, $crypt->decrypt($enc));
    }

    public function testLegacyPlaintextPassthrough(): void
    {
        $crypt = new SecretCrypt();
        $this->assertSame('legacy-pass', $crypt->decrypt('legacy-pass'));
    }
}
