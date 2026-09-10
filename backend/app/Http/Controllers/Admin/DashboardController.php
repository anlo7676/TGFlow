<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke()
    {
        return [
            'users' => DB::table('users')->count(),
            'active_subscriptions' => DB::table('subscriptions')->where('status', 'active')->count(),
            'nodes' => DB::table('proxy_nodes')->count(),
            'online_nodes' => DB::table('proxy_nodes')->where('status', 'online')->count(),
            'today_revenue_cents' => DB::table('payments')->where('status', 'success')->whereDate('paid_at', now()->toDateString())->sum('amount_cents'),
            'month_revenue_cents' => DB::table('payments')->where('status', 'success')->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount_cents'),
            'today_traffic_bytes' => DB::table('traffic_usage_logs')->whereDate('created_at', now()->toDateString())->sum('bytes'),
        ];
    }
}
