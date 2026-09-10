<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        return Plan::orderBy('sort')->paginate(50);
    }

    public function store(Request $request)
    {
        return response()->json(Plan::create($this->validated($request)), 201);
    }

    public function update(Request $request, Plan $plan)
    {
        $plan->update($this->validated($request));
        return $plan->fresh();
    }

    public function destroy(Plan $plan)
    {
        $plan->update(['enabled' => false]);
        return response()->noContent();
    }

    private function validated(Request $request)
    {
        return $request->validate([
            'name' => 'required|string|max:255', 'description' => 'nullable|string|max:5000',
            'price_cents' => 'required|integer|min:0', 'currency' => 'required|string|size:3',
            'traffic_bytes' => 'required|integer|min:1', 'duration_days' => 'required|integer|min:1|max:3650',
            'device_limit' => 'nullable|integer|min:1', 'connection_limit' => 'nullable|integer|min:1',
            'speed_limit_mbps' => 'nullable|integer|min:1', 'node_group_id' => 'required|exists:node_groups,id',
            'reset_mode' => 'required|in:none,monthly', 'sort' => 'integer', 'enabled' => 'boolean',
        ]);
    }
}
