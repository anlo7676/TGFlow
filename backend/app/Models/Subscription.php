<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $guarded = [];
    protected $casts = ['entitlement_snapshot' => 'array', 'started_at' => 'datetime', 'expires_at' => 'datetime'];

    public function getTotalLimitAttribute()
    {
        return (int) $this->traffic_limit_bytes + (int) $this->bonus_traffic_bytes;
    }

    public function getRemainingAttribute()
    {
        return max(0, $this->total_limit - (int) $this->traffic_used_bytes);
    }
}
