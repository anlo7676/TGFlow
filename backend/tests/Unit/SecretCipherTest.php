<?php

namespace Tests\Unit;

use App\Services\Security\SecretCipher;
use App\Services\Security\TotpService;
use RuntimeException;
use Tests\TestCase;

class SecretCipherTest extends TestCase
{
    public function test_aes_gcm_round_trip_and_tamper_detection()
    {
        $cipher = app(SecretCipher::class);
        $encrypted = $cipher->encrypt('top-secret');
        $this->assertNotSame('top-secret', $encrypted);
        $this->assertSame('top-secret', $cipher->decrypt($encrypted));

        $raw = base64_decode($encrypted);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->expectException(RuntimeException::class);
        $cipher->decrypt(base64_encode($raw));
    }

    public function test_totp_secret_is_encrypted_and_rejects_invalid_code()
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $encrypted = $totp->encrypt($secret);
        $this->assertNotSame($secret, $encrypted);
        $this->assertSame($secret, $totp->decrypt($encrypted));
        $this->assertFalse($totp->verify($secret, 'abcdef'));
    }
}
