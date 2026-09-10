<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\OutboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function index()
    {
        return Subscription::latest()->paginate(50);
    }

    public function show(Subscription $subscription)
    {
        return $subscription;
    }

    public function disable(Subscription $subscription, OutboxService $outbox)
    {
        DB::transaction(function () use ($subscription, $outbox) {
            $subscription = Subscription::lockForUpdate()->findOrFail($subscription->id);
            if ($subscription->status !== 'manually_disabled') {
                $subscription->update(['status' => 'manually_disabled', 'desired_state_version' => $subscription->desired_state_version + 1]);
                $outbox->record('subscription', $subscription->id, 'DisableSubscriptionRequested', ['subscription_id' => $subscription->id, 'state_version' => $subscription->desired_state_version, 'reason' => 'manual']);
            }
        });
        return $subscription->fresh();
    }

    public function enable(Subscription $subscription, OutboxService $outbox)
    {
        abort_if($subscription->expires_at->isPast(), 422, '订阅已经到期');
        abort_if($subscription->remaining <= 0, 422, '订阅流量已耗尽');
        DB::transaction(function () use ($subscription, $outbox) {
            $subscription->update(['status' => 'active', 'desired_state_version' => $subscription->desired_state_version + 1]);
            $outbox->record('subscription', $subscription->id, 'EnableSubscriptionRequested', ['subscription_id' => $subscription->id, 'state_version' => $subscription->desired_state_version]);
        });
        return $subscription->fresh();
    }

    public function addTraffic(Request $request, Subscription $subscription)
    {
        $data = $request->validate(['bytes' => 'required|integer|min:1', 'reason' => 'required|string|max:500']);
        DB::transaction(function () use ($request, $subscription, $data) {
            $subscription->increment('bonus_traffic_bytes', $data['bytes']);
            DB::table('subscription_adjustments')->insert(['subscription_id' => $subscription->id, 'type' => 'traffic_bonus', 'value' => $data['bytes'], 'reason' => $data['reason'], 'admin_id' => $request->user()->id, 'created_at' => now()]);
        });
        return $subscription->fresh();
    }

    public function extend(Request $request, Subscription $subscription)
    {
        $days = $request->validate(['days' => 'required|integer|min:1|max:3650'])['days'];
        DB::transaction(function () use ($request, $subscription, $days) {
            $subscription->expires_at = $subscription->expires_at->addDays($days);
            $subscription->save();
            DB::table('subscription_adjustments')->insert(['subscription_id' => $subscription->id, 'type' => 'extend_days', 'value' => $days, 'reason' => '管理员延期', 'admin_id' => $request->user()->id, 'created_at' => now()]);
        });
        return $subscription;
    }
}
