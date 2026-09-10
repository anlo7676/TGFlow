<?php

namespace App\Jobs;

use App\Models\Plan;
use App\Models\ProxySecret;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Payment\PaymentGateway;
use App\Services\Security\SecretCipher;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $tries = 5;
    public $backoff = [5, 15, 30, 60, 300];
    private $update;

    public function __construct(array $update) { $this->update = $update; }

    public function handle(TelegramBotService $bot, OrderService $orders, PaymentGateway $payments, SecretCipher $cipher)
    {
        $message = isset($this->update['message']) ? $this->update['message'] : null;
        $callback = isset($this->update['callback_query']) ? $this->update['callback_query'] : null;
        $from = $message ? $message['from'] : ($callback ? $callback['from'] : null);
        if (!$from || !isset($from['id'])) return;
        $user = User::updateOrCreate(['telegram_id' => $from['id']], [
            'telegram_username' => isset($from['username']) ? $from['username'] : null,
            'telegram_first_name' => isset($from['first_name']) ? $from['first_name'] : null,
            'telegram_language' => isset($from['language_code']) ? $from['language_code'] : null,
            'status' => 'active',
        ]);
        if ($user->status !== 'active') return;
        $chatId = $message ? $message['chat']['id'] : $callback['message']['chat']['id'];
        $command = $message ? trim(isset($message['text']) ? $message['text'] : '') : (isset($callback['data']) ? $callback['data'] : '');

        if ($command === '/start' || $command === 'menu') {
            $bot->send($chatId, '欢迎使用 TGFlow Proxy', [[['text' => '🛒 购买套餐', 'callback_data' => 'plans']], [['text' => '📊 流量查询', 'callback_data' => 'usage'], ['text' => '🌐 我的节点', 'callback_data' => 'nodes']]]);
        } elseif ($command === 'plans') {
            $buttons = Plan::where('enabled', true)->orderBy('sort')->get()->map(function ($plan) {
                return [['text' => $plan->name.' / ¥'.number_format($plan->price_cents / 100, 2), 'callback_data' => 'buy:'.$plan->id]];
            })->all();
            $bot->send($chatId, '请选择套餐', $buttons);
        } elseif (strpos($command, 'buy:') === 0) {
            $planId = substr($command, 4);
            abort_unless(ctype_digit($planId), 422);
            $plan = Plan::where('enabled', true)->findOrFail($planId);
            $order = $orders->create($user, $plan);
            $url = $payments->createPayment($order);
            $bot->send($chatId, '订单：'.e($order->order_no)."\n套餐：".e($plan->name)."\n金额：¥".number_format($order->amount_cents / 100, 2), [[['text' => '💳 前往支付', 'url' => $url]]]);
        } elseif ($command === 'usage') {
            $subscription = Subscription::where('user_id', $user->id)->latest()->first();
            $text = $subscription ? '状态：'.e($subscription->status)."\n已使用：".$this->gb($subscription->traffic_used_bytes)."\n剩余：".$this->gb($subscription->remaining)."\n到期：".$subscription->expires_at->utc()->format('Y-m-d H:i').' UTC' : '暂无订阅';
            $bot->send($chatId, $text);
        } elseif ($command === 'nodes') {
            $subscription = Subscription::where('user_id', $user->id)->where('status', 'active')->latest()->first();
            if (!$subscription) { $bot->send($chatId, '当前没有可用订阅'); return; }
            $buttons = ProxySecret::query()->join('proxy_nodes', 'proxy_nodes.id', '=', 'proxy_secrets.proxy_node_id')
                ->where('proxy_secrets.subscription_id', $subscription->id)->where('proxy_secrets.status', 'active')->where('proxy_nodes.status', 'online')
                ->get(['proxy_secrets.*', 'proxy_nodes.name', 'proxy_nodes.public_host', 'proxy_nodes.public_port'])
                ->map(function ($secret) use ($cipher) {
                    $value = $cipher->decrypt($secret->secret_encrypted, $secret->secret_key_version);
                    $url = 'https://t.me/proxy?'.http_build_query(['server' => $secret->public_host, 'port' => $secret->public_port, 'secret' => $value], '', '&', PHP_QUERY_RFC3986);
                    return [['text' => '连接 '.$secret->name, 'url' => $url]];
                })->all();
            $bot->send($chatId, $buttons ? '请选择在线节点' : '当前没有在线节点', $buttons);
        }
        DB::table('processed_telegram_updates')->where('update_id', $this->update['update_id'])->update(['status' => 'processed', 'processed_at' => now()]);
    }

    private function gb($bytes) { return number_format($bytes / 1073741824, 2).' GB'; }
}
