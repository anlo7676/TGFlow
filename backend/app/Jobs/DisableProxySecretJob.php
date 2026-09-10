<?php

namespace App\Jobs;

use App\Models\ProxyNode;
use App\Models\ProxySecret;
use App\Services\Proxy\ProxyEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DisableProxySecretJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $tries = 5;
    public $backoff = [5, 15, 30, 60, 300];
    private $secretId;
    private $stateVersion;

    public function __construct($secretId, $stateVersion) { $this->secretId = $secretId; $this->stateVersion = $stateVersion; }

    public function handle(ProxyEngine $engine)
    {
        $secret = ProxySecret::findOrFail($this->secretId);
        if ($secret->applied_state_version > $this->stateVersion || $secret->status === 'disabled') return;
        if ($secret->remote_identifier) $engine->disableSecret(ProxyNode::findOrFail($secret->proxy_node_id), $secret->remote_identifier, $this->stateVersion);
        $secret->update(['status' => 'disabled', 'applied_state_version' => $this->stateVersion, 'last_sync_at' => now()]);
    }
}
