<?php

namespace App\Jobs;

use App\Models\ProxyNode;
use App\Models\ProxySecret;
use App\Models\Subscription;
use App\Services\Proxy\ProxyEngine;
use App\Services\Security\SecretCipher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CreateProxySecretJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $tries = 5;
    public $backoff = [5, 15, 30, 60, 300];
    private $secretId;
    private $stateVersion;

    public function __construct($secretId, $stateVersion) { $this->secretId = $secretId; $this->stateVersion = $stateVersion; }

    public function handle(ProxyEngine $engine, SecretCipher $cipher)
    {
        $secret = ProxySecret::findOrFail($this->secretId);
        $subscription = Subscription::findOrFail($secret->subscription_id);
        if ($secret->status === 'active' || $subscription->status !== 'active' || $subscription->desired_state_version != $this->stateVersion) return;
        $node = ProxyNode::findOrFail($secret->proxy_node_id);
        try {
            $secretValue = $cipher->decrypt($secret->secret_encrypted, $secret->secret_key_version);
            $remoteId = $engine->createSecret($node, $secret->label, $secretValue, $secret->allocated_quota_bytes, $subscription->expires_at, $subscription->connection_limit);
            if (!preg_match('/^[a-zA-Z0-9_.:-]{1,255}$/', $remoteId)) throw new \RuntimeException('节点返回的 Secret 标识格式无效');
            $secret->update(['remote_identifier' => mb_substr($remoteId, 0, 255), 'status' => 'active', 'applied_state_version' => $this->stateVersion, 'last_sync_at' => now()]);
        } catch (Throwable $exception) {
            $secret->update(['status' => 'error']);
            throw $exception;
        }
    }
}
