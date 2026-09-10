<?php

namespace App\Services\Security;

use RuntimeException;

class SecretCipher
{
    public function encrypt($plaintext)
    {
        $key = $this->key();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) {
            throw new RuntimeException('敏感数据加密失败');
        }
        return base64_encode($iv.$tag.$ciphertext);
    }

    public function decrypt($encoded, $version = null)
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('敏感数据格式无效');
        }
        $plaintext = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key($version), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plaintext === false) {
            throw new RuntimeException('敏感数据认证失败');
        }
        return $plaintext;
    }

    public function version()
    {
        return (string) config('security.master_key_version');
    }

    private function key($version = null)
    {
        $version = $version ?: $this->version();
        $encoded = $version === $this->version()
            ? config('security.master_key')
            : (isset(config('security.previous_master_keys')[$version]) ? config('security.previous_master_keys')[$version] : null);
        $key = base64_decode((string) $encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('SSH_MASTER_KEY 必须是 Base64 编码的 32 字节独立密钥');
        }
        return $key;
    }
}
