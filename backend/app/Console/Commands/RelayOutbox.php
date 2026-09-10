<?php

namespace App\Console\Commands;

use App\Jobs\DisableSubscriptionJob;
use App\Jobs\ProvisionSubscriptionJob;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;

class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay {--limit=100}';
    protected $description = '发布尚未处理的事务 Outbox 事件';

    public function handle()
    {
        OutboxEvent::whereNull('published_at')->where('available_at', '<=', now())->orderBy('created_at')
            ->limit((int) $this->option('limit'))->get()->each(function (OutboxEvent $event) {
                $payload = $event->payload;
                if ($event->event_type === 'SubscriptionProvisionRequested') {
                    ProvisionSubscriptionJob::dispatch($payload['subscription_id'], $payload['state_version']);
                } elseif ($event->event_type === 'DisableSubscriptionRequested') {
                    DisableSubscriptionJob::dispatch($payload['subscription_id'], $payload['state_version']);
                }
                $event->update(['published_at' => now(), 'attempts' => $event->attempts + 1]);
            });
        return 0;
    }
}
