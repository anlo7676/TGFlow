<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function create(User $user, Plan $plan)
    {
        abort_unless($plan->enabled, 422, '套餐不可购买');

        return DB::transaction(function () use ($user, $plan) {
            return Order::create([
                'order_no' => $this->nextOrderNo(),
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'amount_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'plan_snapshot' => $plan->snapshot(),
                'status' => 'pending',
                'payment_method' => 'epay',
                'expired_at' => now()->addMinutes(30),
            ]);
        });
    }

    private function nextOrderNo()
    {
        do {
            $number = 'TG'.now()->format('ymdHis').strtoupper(Str::random(8));
        } while (Order::where('order_no', $number)->exists());

        return $number;
    }
}
