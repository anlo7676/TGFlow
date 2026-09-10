<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdateJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TelegramController extends Controller
{
    public function webhook(Request $request)
    {
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        $expected = (string) config('security.telegram_webhook_secret');
        abort_unless($expected !== '' && hash_equals($expected, $provided), 403);
        abort_unless(strpos((string) $request->header('Content-Type'), 'application/json') === 0, 415);
        abort_if(strlen($request->getContent()) > 1048576, 413);
        $update = $request->validate(['update_id' => 'required|integer']);

        DB::transaction(function () use ($request, $update) {
            $inserted = DB::table('processed_telegram_updates')->insertOrIgnore([
                'update_id' => $update['update_id'], 'received_at' => now(), 'status' => 'received',
            ]);
            if (!$inserted) {
                return;
            }
            ProcessTelegramUpdateJob::dispatch($request->json()->all());
        });
        return response()->json(['ok' => true]);
    }
}
