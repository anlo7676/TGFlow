<?php

namespace App\Services\Proxy;

use App\Models\ProxyNode;
use App\Services\SSH\SSHClient;
use RuntimeException;

class MTProxyMaxEngine implements ProxyEngine
{
    private $sshClient;

    public function __construct(SSHClient $sshClient)
    {
        $this->sshClient = $sshClient;
    }

    public function installNode(ProxyNode $node)
    {
        $version = (string) config('proxy.mtproxymax_version');
        $checksum = strtolower((string) config('proxy.mtproxymax_sha256'));
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version) || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new RuntimeException('部署版本或 SHA-256 尚未安全配置');
        }
        $args = [$version, $checksum, (string) $node->public_port];
        if ($node->fake_tls_domain) $args[] = $node->fake_tls_domain;
        return $this->run($node, 'install', $args);
    }

    public function healthCheck(ProxyNode $node)
    {
        $ssh = $this->sshClient->connect($node->server);
        return trim($ssh->exec('systemctl is-active mtproxymax')) === 'active';
    }

    public function createSecret(ProxyNode $node, $label, $secret, $quota, $expiry, $connectionLimit = null)
    {
        $this->assertIdentifier($label);
        if (!preg_match('/^[a-f0-9]{32}$/', $secret)) throw new RuntimeException('Proxy Secret 格式无效');
        $args = [$label, $secret, (string) $quota, $expiry->format('c')];
        if ($connectionLimit !== null) {
            $args[] = (string) $connectionLimit;
        }
        return $this->run($node, 'create-secret', $args);
    }

    public function disableSecret(ProxyNode $node, $remoteIdentifier, $stateVersion)
    {
        return $this->run($node, 'disable-secret', [$remoteIdentifier, (string) $stateVersion]);
    }

    public function enableSecret(ProxyNode $node, $remoteIdentifier, $stateVersion)
    {
        return $this->run($node, 'enable-secret', [$remoteIdentifier, (string) $stateVersion]);
    }

    public function getTraffic(ProxyNode $node, $remoteIdentifier)
    {
        return $this->run($node, 'traffic', [$remoteIdentifier]);
    }

    private function run(ProxyNode $node, $action, array $args)
    {
        $this->assertIdentifier($action);
        foreach ($args as $arg) {
            if (strlen($arg) > 128 || preg_match('/[^a-zA-Z0-9_.:@+-]/', $arg)) {
                throw new RuntimeException('节点参数包含不允许的字符');
            }
        }
        $ssh = $this->sshClient->connect($node->server);
        $command = '/usr/local/sbin/tgflow-mtproxy '.escapeshellarg($action);
        foreach ($args as $arg) {
            $command .= ' '.escapeshellarg($arg);
        }
        return trim($ssh->exec($command));
    }

    private function assertIdentifier($value)
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,96}$/', $value)) {
            throw new RuntimeException('节点标识格式无效');
        }
    }
}
