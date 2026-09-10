<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Mysql57IntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql57_triggers_reject_invalid_subscription_dates()
    {
        if (DB::getDriverName() !== 'mysql' || strpos(DB::selectOne('SELECT VERSION() AS version')->version, '5.7.44') !== 0) {
            $this->markTestSkipped('仅在 MySQL 5.7.44 集成测试中运行');
        }

        $user = User::create(['telegram_id' => 99112233, 'status' => 'active']);
        $groupId = DB::table('node_groups')->insertGetId(['name' => 'Trigger Test', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $plan = Plan::create(['name' => 'Trigger Test', 'price_cents' => 100, 'currency' => 'CNY', 'traffic_bytes' => 1000, 'duration_days' => 30, 'node_group_id' => $groupId, 'reset_mode' => 'none', 'enabled' => true]);

        $this->expectException(QueryException::class);
        Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'traffic_limit_bytes' => 1000, 'traffic_used_bytes' => 0, 'bonus_traffic_bytes' => 0,
            'started_at' => now(), 'expires_at' => now()->subDay(),
            'status' => 'active', 'desired_state_version' => 0,
            'entitlement_snapshot' => $plan->snapshot(),
        ]);
    }
}
