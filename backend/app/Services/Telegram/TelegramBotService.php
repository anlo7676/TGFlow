<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotService
{
    public function send($chatId, $text, array $buttons = [])
    {
        $token = (string) env('TELEGRAM_BOT_TOKEN');
        if ($token === '') throw new RuntimeException('Telegram Bot Token 未配置');
        $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($buttons) $payload['reply_markup'] = ['inline_keyboard' => $buttons];
        $response = Http::timeout(10)->retry(3, 500)->post('https://api.telegram.org/bot'.$token.'/sendMessage', $payload);
        if (!$response->successful()) throw new RuntimeException('Telegram 消息发送失败，HTTP '.$response->status());
    }
}
