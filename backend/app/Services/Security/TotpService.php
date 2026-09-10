<?php

namespace App\Services\Security;

use RuntimeException;

class TotpService
{
    public function generateSecret()
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function verify($secret, $code, $time = null)
    {
        if (!preg_match('/^[0-9]{6}$/', (string) $code)) return false;
        $counter = (int) floor(($time === null ? time() : $time) / 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals($this->code($secret, $counter + $offset), (string) $code)) return true;
        }
        return false;
    }

    public function encrypt($secret)
    {
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt($secret, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($encrypted === false) throw new RuntimeException('2FA 密钥加密失败');
        return base64_encode($iv.$tag.$encrypted);
    }

    public function decrypt($value)
    {
        $raw = base64_decode($value, true);
        $result = $raw === false || strlen($raw) < 29 ? false : openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($result === false) throw new RuntimeException('2FA 密钥解密失败');
        return $result;
    }

    private function code($secret, $counter)
    {
        $binary = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binary, $this->base32Decode($secret), true);
        $offset = ord(substr($hash, -1)) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function key()
    {
        $key = base64_decode((string) config('security.admin_totp_key'), true);
        if ($key === false || strlen($key) !== 32) throw new RuntimeException('ADMIN_TOTP_KEY 必须是 Base64 编码的 32 字节密钥');
        return $key;
    }

    private function base32Encode($data)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = '';
        foreach (str_split($data) as $char) $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        $output = '';
        foreach (str_split($bits, 5) as $chunk) $output .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        return $output;
    }

    private function base32Decode($value)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = '';
        foreach (str_split(strtoupper($value)) as $char) {
            $position = strpos($alphabet, $char);
            if ($position === false) throw new RuntimeException('2FA 密钥格式无效');
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 8) as $chunk) if (strlen($chunk) === 8) $output .= chr(bindec($chunk));
        return $output;
    }
}
