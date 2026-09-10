<?php

namespace App\Jobs;

use App\Models\ProxyNode;
use App\Services\Proxy\ProxyEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeployProxyNodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $tries = 5;
    public $backoff = [5, 15, 30, 60, 300];
    public $timeout = 300;
    private $deploymentId;

    public function __construct($deploymentId) { $this->deploymentId = $deploymentId; }

    public function handle(ProxyEngine $engine)
    {
        $deployment = DB::table('deployments')->where('id', $this->deploymentId)->first();
        if (!$deployment || $deployment->status === 'success') return;
        DB::table('deployments')->where('id', $this->deploymentId)->update(['status' => 'running', 'started_at' => now()]);
        $node = ProxyNode::findOrFail($deployment->proxy_node_id);
        $node->update(['status' => 'deploying']);
        try {
            $output = $this->sanitize($engine->installNode($node));
            $node->update(['status' => 'online', 'last_health_check_at' => now()]);
            DB::table('deployments')->where('id', $this->deploymentId)->update(['status' => 'success', 'logs' => $output, 'logs_truncated' => strlen($output) >= 65536, 'finished_at' => now()]);
        } catch (Throwable $e) {
            $node->update(['status' => 'error']);
            DB::table('deployments')->where('id', $this->deploymentId)->update(['status' => 'failed', 'logs' => '部署失败，错误类型：'.get_class($e), 'finished_at' => now()]);
            throw $e;
        }
    }

    private function sanitize($value)
    {
        $value = preg_replace('/\x1B(?:[@-_][0-?]*[ -\/]*[@-~]|\][^\x07]*(?:\x07|\x1B\\))/', '', (string) $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        return mb_substr($value, 0, 65536);
    }
}
