<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $guarded = [];
    protected $casts = ['enabled' => 'boolean'];

    public function snapshot()
    {
        return [
            'plan_id' => $this->id,
            'name' => $this->name,
            'price_cents' => (int) $this->price_cents,
            'currency' => $this->currency,
            'traffic_bytes' => (int) $this->traffic_bytes,
            'duration_days' => (int) $this->duration_days,
            'node_group_id' => (int) $this->node_group_id,
            'device_limit' => $this->device_limit,
            'connection_limit' => $this->connection_limit,
            'speed_limit_mbps' => $this->speed_limit_mbps,
            'reset_mode' => $this->reset_mode,
        ];
    }
}
