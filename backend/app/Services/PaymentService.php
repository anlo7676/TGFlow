<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    private $outbox;

    public function __construct(OutboxService $outbox)
    {
        $this->outbox = $outbox;
    }

    public function confirm(array $notice)
    {
        return DB::transaction(function () use ($notice) {
            $order = Order::where('order_no', $notice['out_trade_no'])->lockForUpdate()->firstOrFail();
            $amount = $this->moneyToCents((string) $notice['money']);
            if ($amount !== (int) $order->amount_cents || strtoupper($notice['currency']) !== $order->currency) {
                throw ValidationException::withMessages(['payment' => '支付金额或币种不匹配']);
            }

            $existingTrade = Payment::where('provider', 'epay')->where('provider_trade_no', $notice['trade_no'])->first();
            if ($existingTrade && (int) $existingTrade->order_id !== (int) $order->id) {
                throw ValidationException::withMessages(['trade_no' => '渠道交易号已绑定其他订单']);
            }
            if ($order->status === 'paid') {
                return Subscription::where('order_id', $order->id)->firstOrFail();
            }

            Payment::updateOrCreate(
                ['provider' => 'epay', 'provider_trade_no' => $notice['trade_no']],
                [
                    'order_id' => $order->id,
                    'trade_no' => $notice['out_trade_no'],
                    'amount_cents' => $amount,
                    'currency' => strtoupper($notice['currency']),
                    'status' => 'success',
                    'callback_payload_redacted' => $this->redact($notice),
                    'paid_at' => now(),
                ]
            );

            $snapshot = $order->plan_snapshot;
            $subscription = Subscription::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'user_id' => $order->user_id,
                    'plan_id' => $order->plan_id,
                    'traffic_limit_bytes' => $snapshot['traffic_bytes'],
                    'traffic_used_bytes' => 0,
                    'bonus_traffic_bytes' => 0,
                    'started_at' => now(),
                    'expires_at' => now()->addDays($snapshot['duration_days']),
                    'status' => 'active',
                    'desired_state_version' => 1,
                    'device_limit' => $snapshot['device_limit'],
                    'connection_limit' => $snapshot['connection_limit'],
                    'speed_limit_mbps' => $snapshot['speed_limit_mbps'],
                    'entitlement_snapshot' => $snapshot,
                ]
            );
            $order->update(['status' => 'paid', 'paid_at' => now()]);
            $this->outbox->record('subscription', $subscription->id, 'SubscriptionProvisionRequested', [
                'subscription_id' => $subscription->id,
                'state_version' => $subscription->desired_state_version,
            ]);

            return $subscription;
        });
    }

    private function redact(array $notice)
    {
        return array_intersect_key($notice, array_flip(['pid', 'trade_no', 'out_trade_no', 'money', 'currency', 'trade_status', 'type']));
    }

    private function moneyToCents($money)
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,2}))?$/', $money, $match)) {
            throw ValidationException::withMessages(['money' => '支付金额格式无效']);
        }
        return ((int) $match[1] * 100) + (int) str_pad(isset($match[2]) ? $match[2] : '', 2, '0');
    }
}
