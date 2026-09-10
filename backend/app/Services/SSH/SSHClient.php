<?php

namespace App\Services\SSH;

use App\Models\ProxyServer;
use App\Services\Security\SecretCipher;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

class SSHClient
{
    private $cipher;

    public function __construct(SecretCipher $cipher)
    {
        $this->cipher = $cipher;
    }

    public function connect(ProxyServer $server)
    {
        $this->assertSafeTarget($server->host);
        $ssh = new SSH2($server->host, $server->ssh_port, 10);
        $hostKey = $ssh->getServerPublicHostKey();
        $actual = $hostKey ? strtolower(PublicKeyLoader::load($hostKey)->getFingerprint('sha256')) : '';
        $expected = strtolower(trim($server->ssh_host_fingerprint));
        if (!$actual || !hash_equals($expected, $actual)) {
            throw new RuntimeException('SSH 主机指纹不匹配，拒绝连接');
        }
        $credential = $server->ssh_auth_type === 'key'
            ? PublicKeyLoader::load($this->cipher->decrypt($server->ssh_private_key_encrypted, $server->ssh_credential_key_version))
            : $this->cipher->decrypt($server->ssh_password_encrypted, $server->ssh_credential_key_version);
        if (!$ssh->login($server->ssh_username, $credential)) {
            throw new RuntimeException('SSH 认证失败');
        }
        return $ssh;
    }

    private function assertSafeTarget($host)
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if (!$ips) {
            throw new RuntimeException('SSH 主机无法解析');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('默认拒绝内网、环回、链路本地和保留地址');
            }
        }
    }
}
