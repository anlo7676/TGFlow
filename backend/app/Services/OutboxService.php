<?php

namespace App\Services;

use App\Models\OutboxEvent;
use Illuminate\Support\Str;

class OutboxService
{
    public function record($aggregateType, $aggregateId, $eventType, array $payload)
    {
        return OutboxEvent::create([
            'id' => (string) Str::uuid(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => $payload,
            'available_at' => now(),
            'created_at' => now(),
        ]);
    }
}
