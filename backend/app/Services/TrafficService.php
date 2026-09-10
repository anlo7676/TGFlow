<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\TrafficUsageLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TrafficService
{
    private $outbox;

    public function __construct(OutboxService $outbox)
    {
        $this->outbox = $outbox;
    }

    public function record(array $observation)
    {
        if (!isset($observation['bytes']) || !is_int($observation['bytes']) || $observation['bytes'] < 0) {
            throw ValidationException::withMessages(['bytes' => '流量必须是非负整数 Bytes']);
        }
        if (!isset($observation['observation_id']) || !preg_match('/^[a-zA-Z0-9:_.-]{1,96}$/', $observation['observation_id'])) {
            throw ValidationException::withMessages(['observation_id' => '观测幂等键格式无效']);
        }
        return DB::transaction(function () use ($observation) {
            $subscription = Subscription::lockForUpdate()->findOrFail($observation['subscription_id']);
            $inserted = TrafficUsageLog::insertOrIgnore([
                'subscription_id' => $subscription->id,
                'proxy_node_id' => $observation['proxy_node_id'],
                'bytes' => $observation['bytes'],
                'direction' => isset($observation['direction']) ? $observation['direction'] : null,
                'period_start' => $observation['period_start'],
                'period_end' => $observation['period_end'],
                'observation_id' => $observation['observation_id'],
                'counter_epoch' => $observation['counter_epoch'],
                'source_sequence' => isset($observation['source_sequence']) ? $observation['source_sequence'] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                return $subscription;
            }

            if ((int) $subscription->traffic_used_bytes > PHP_INT_MAX - $observation['bytes']) {
                throw ValidationException::withMessages(['bytes' => '流量计数溢出']);
            }
            $subscription->traffic_used_bytes = (int) $subscription->traffic_used_bytes + $observation['bytes'];
            if ($subscription->status === 'active' && $subscription->traffic_used_bytes >= $subscription->total_limit) {
                $subscription->status = 'traffic_exhausted';
                $subscription->desired_state_version++;
                $this->outbox->record('subscription', $subscription->id, 'DisableSubscriptionRequested', [
                    'subscription_id' => $subscription->id,
                    'state_version' => $subscription->desired_state_version,
                    'reason' => 'traffic_exhausted',
                ]);
            }
            $subscription->save();
            return $subscription->fresh();
        });
    }
}
