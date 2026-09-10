<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\OutboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';
    protected $description = '到期订阅状态转换并写入禁用 Outbox';

    public function handle(OutboxService $outbox)
    {
        Subscription::where('status', 'active')->where('expires_at', '<=', now())->pluck('id')->each(function ($id) use ($outbox) {
            DB::transaction(function () use ($id, $outbox) {
                $subscription = Subscription::lockForUpdate()->find($id);
                if (!$subscription || $subscription->status !== 'active' || $subscription->expires_at->isFuture()) {
                    return;
                }
                $subscription->status = 'expired';
                $subscription->desired_state_version++;
                $subscription->save();
                $outbox->record('subscription', $subscription->id, 'DisableSubscriptionRequested', [
                    'subscription_id' => $subscription->id,
                    'state_version' => $subscription->desired_state_version,
                    'reason' => 'expired',
                ]);
            });
        });
        return 0;
    }
}
