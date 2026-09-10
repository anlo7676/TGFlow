<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Services\Security\SecretCipher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProvisionSubscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $tries = 5;
    public $backoff = [5, 15, 30, 60, 300];
    private $subscriptionId;
    private $stateVersion;

    public function __construct($subscriptionId, $stateVersion)
    {
        $this->subscriptionId = $subscriptionId;
        $this->stateVersion = $stateVersion;
    }

    public function handle(SecretCipher $cipher)
    {
        $secretIds = DB::transaction(function () use ($cipher) {
            $subscription = Subscription::lockForUpdate()->findOrFail($this->subscriptionId);
            if ($subscription->status !== 'active' || $subscription->desired_state_version != $this->stateVersion) {
                return [];
            }
            $nodeIds = DB::table('node_group_members')
                ->join('proxy_nodes', 'proxy_nodes.id', '=', 'node_group_members.proxy_node_id')
                ->where('node_group_members.node_group_id', $subscription->entitlement_snapshot['node_group_id'])
                ->whereIn('proxy_nodes.status', ['online', 'pending'])
                ->pluck('proxy_nodes.id');
            if ($nodeIds->isEmpty()) return [];
            $localQuota = intdiv($subscription->remaining, $nodeIds->count());
            foreach ($nodeIds as $nodeId) {
                DB::table('subscription_nodes')->insertOrIgnore([
                    'subscription_id' => $subscription->id, 'proxy_node_id' => $nodeId,
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('proxy_secrets')->insertOrIgnore([
                    'subscription_id' => $subscription->id, 'proxy_node_id' => $nodeId,
                    'label' => 'sub_'.$subscription->id.'_node_'.$nodeId,
                    'secret_encrypted' => $cipher->encrypt(bin2hex(random_bytes(16))),
                    'secret_key_version' => $cipher->version(), 'status' => 'pending',
                    'allocated_quota_bytes' => $localQuota,
                    'applied_state_version' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return DB::table('proxy_secrets')->where('subscription_id', $subscription->id)->whereIn('status', ['pending', 'error'])->pluck('id')->all();
        });
        foreach ($secretIds as $secretId) CreateProxySecretJob::dispatch($secretId, $this->stateVersion);
    }
}
