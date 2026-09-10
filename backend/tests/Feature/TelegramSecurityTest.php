<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdateJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_requires_secret_header_and_deduplicates_update()
    {
        Queue::fake();
        $payload = ['update_id' => 42, 'message' => ['from' => ['id' => 778899, 'username' => 'first']]];
        $this->postJson('/api/telegram/webhook', $payload)->assertForbidden();
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret-32-characters'];
        $this->postJson('/api/telegram/webhook', $payload, $headers)->assertOk();
        $payload['message']['from']['username'] = 'replayed-change';
        $this->postJson('/api/telegram/webhook', $payload, $headers)->assertOk();

        $this->assertDatabaseCount('processed_telegram_updates', 1);
        Queue::assertPushed(ProcessTelegramUpdateJob::class, 1);
    }
}
