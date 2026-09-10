<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TrafficService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_payment_callback_creates_one_subscription_and_one_outbox_event()
    {
        list($user, $plan) = $this->catalog();
        $order = app(OrderService::class)->create($user, $plan);
        $notice = [
            'out_trade_no' => $order->order_no, 'trade_no' => 'EPAY-UNIQUE-1',
            'money' => '15.00', 'currency' => 'CNY', 'trade_status' => 'TRADE_SUCCESS', 'pid' => 'merchant',
        ];

        app(PaymentService::class)->confirm($notice);
        app(PaymentService::class)->confirm($notice);

        $this->assertSame(1, Subscription::where('order_id', $order->id)->count());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_plan_snapshot_remains_immutable_after_plan_change()
    {
        list($user, $plan) = $this->catalog();
        $order = app(OrderService::class)->create($user, $plan);
        $plan->update(['traffic_bytes' => 999, 'duration_days' => 1]);
        app(PaymentService::class)->confirm([
            'out_trade_no' => $order->order_no, 'trade_no' => 'EPAY-UNIQUE-2',
            'money' => '15', 'currency' => 'CNY', 'trade_status' => 'TRADE_SUCCESS', 'pid' => 'merchant',
        ]);

        $subscription = Subscription::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(1000, (int) $subscription->traffic_limit_bytes);
        $this->assertSame(30, $subscription->started_at->diffInDays($subscription->expires_at));
    }

    public function test_multi_node_observations_share_quota_and_duplicates_are_ignored()
    {
        list($user, $plan, $nodes) = $this->catalog(true);
        $subscription = $this->subscription($user, $plan, 1000);
        $traffic = app(TrafficService::class);
        $traffic->record($this->observation($subscription->id, $nodes[0], 'obs-a', 400));
        $traffic->record($this->observation($subscription->id, $nodes[1], 'obs-b', 500));
        $traffic->record($this->observation($subscription->id, $nodes[0], 'obs-a', 400));

        $this->assertSame(900, (int) $subscription->fresh()->traffic_used_bytes);
        $this->assertSame('active', $subscription->fresh()->status);

        $traffic->record($this->observation($subscription->id, $nodes[0], 'obs-c', 100));
        $this->assertSame('traffic_exhausted', $subscription->fresh()->status);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DisableSubscriptionRequested']);
    }

    public function test_expired_subscription_transitions_once()
    {
        list($user, $plan) = $this->catalog();
        $subscription = $this->subscription($user, $plan, 1000, now()->subMinute());
        $this->artisan('subscriptions:expire')->assertExitCode(0);
        $this->artisan('subscriptions:expire')->assertExitCode(0);
        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'DisableSubscriptionRequested')->count());
    }

    private function catalog($withNodes = false)
    {
        $user = User::create(['telegram_id' => 123456789, 'status' => 'active']);
        $groupId = DB::table('node_groups')->insertGetId(['name' => 'Standard', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $plan = Plan::create(['name' => '基础套餐', 'price_cents' => 1500, 'currency' => 'CNY', 'traffic_bytes' => 1000, 'duration_days' => 30, 'node_group_id' => $groupId, 'reset_mode' => 'none', 'enabled' => true]);
        if (!$withNodes) {
            return [$user, $plan];
        }
        $serverId = DB::table('proxy_servers')->insertGetId(['name' => 'test', 'host' => '203.0.113.1', 'ssh_port' => 22, 'ssh_username' => 'proxy', 'ssh_auth_type' => 'key', 'ssh_host_fingerprint' => 'fingerprint', 'status' => 'online', 'created_at' => now(), 'updated_at' => now()]);
        $nodes = [];
        foreach ([['Tokyo', 443], ['Singapore', 444]] as $entry) {
            $nodes[] = DB::table('proxy_nodes')->insertGetId(['proxy_server_id' => $serverId, 'name' => $entry[0], 'region' => $entry[0], 'country_code' => 'JP', 'public_host' => 'proxy.example.com', 'public_port' => $entry[1], 'engine' => 'mtproxymax', 'status' => 'online', 'weight' => 100, 'created_at' => now(), 'updated_at' => now()]);
        }
        return [$user, $plan, $nodes];
    }

    private function subscription(User $user, Plan $plan, $limit, $expiresAt = null)
    {
        return Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'traffic_limit_bytes' => $limit, 'traffic_used_bytes' => 0, 'bonus_traffic_bytes' => 0, 'started_at' => now()->subDay(), 'expires_at' => $expiresAt ?: now()->addMonth(), 'status' => 'active', 'desired_state_version' => 0, 'entitlement_snapshot' => $plan->snapshot()]);
    }

    private function observation($subscriptionId, $nodeId, $id, $bytes)
    {
        return ['subscription_id' => $subscriptionId, 'proxy_node_id' => $nodeId, 'bytes' => $bytes, 'period_start' => now()->subMinute(), 'period_end' => now(), 'observation_id' => $id, 'counter_epoch' => 1, 'source_sequence' => 1];
    }
}
