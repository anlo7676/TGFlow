<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DisableSubscriptionJob implements ShouldQueue
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

    public function handle()
    {
        DB::table('subscription_nodes')->where('subscription_id', $this->subscriptionId)
            ->update(['status' => 'disabled', 'updated_at' => now()]);
        DB::table('proxy_secrets')->where('subscription_id', $this->subscriptionId)->pluck('id')->each(function ($secretId) {
            DisableProxySecretJob::dispatch($secretId, $this->stateVersion);
        });
    }
}
