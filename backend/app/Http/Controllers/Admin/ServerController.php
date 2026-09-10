<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProxyServer;
use App\Jobs\DeployProxyNodeJob;
use App\Services\Security\SecretCipher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServerController extends Controller
{
    public function index()
    {
        return ProxyServer::latest()->paginate(50);
    }

    public function store(Request $request, SecretCipher $cipher)
    {
        $data = $this->validated($request, true);
        $this->encryptCredentials($data, $cipher);
        return response()->json(ProxyServer::create($data), 201);
    }

    public function update(Request $request, ProxyServer $server, SecretCipher $cipher)
    {
        $data = $this->validated($request, false);
        if ($data['ssh_auth_type'] !== $server->ssh_auth_type && empty($data[$data['ssh_auth_type'] === 'key' ? 'ssh_private_key' : 'ssh_password'])) {
            abort(422, '更换认证方式时必须同时提供新凭据');
        }
        $this->encryptCredentials($data, $cipher);
        $server->update($data);
        return $server->fresh();
    }

    public function disable(ProxyServer $server)
    {
        abort_if(DB::table('proxy_nodes')->join('subscription_nodes', 'subscription_nodes.proxy_node_id', '=', 'proxy_nodes.id')->where('proxy_nodes.proxy_server_id', $server->id)->where('subscription_nodes.status', 'active')->exists(), 422, '服务器仍承载活跃订阅');
        $server->update(['status' => 'disabled']);
        return $server;
    }

    public function deploy(ProxyServer $server)
    {
        $node = DB::table('proxy_nodes')->where('proxy_server_id', $server->id)->whereNotIn('status', ['disabled'])->first();
        abort_unless($node, 422, '请先为服务器创建代理节点');
        $id = DB::table('deployments')->insertGetId(['proxy_server_id' => $server->id, 'proxy_node_id' => $node->id, 'type' => 'install', 'status' => 'pending', 'created_at' => now()]);
        DeployProxyNodeJob::dispatch($id);
        return response()->json(['deployment_id' => $id, 'status' => 'pending'], 202);
    }

    private function validated(Request $request, $creating)
    {
        $rules = [
            'name' => 'required|string|max:255', 'host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.-]+$/'],
            'ipv4' => 'nullable|ipv4', 'ipv6' => 'nullable|ipv6', 'ssh_port' => 'required|integer|min:1|max:65535',
            'ssh_username' => ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/i'],
            'ssh_auth_type' => 'required|in:key,password', 'ssh_private_key' => 'nullable|string|max:16384',
            'ssh_password' => 'nullable|string|max:1024', 'ssh_host_fingerprint' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9+\/:=_-]+$/'],
        ];
        $data = $request->validate($rules);
        if ($creating && empty($data[$data['ssh_auth_type'] === 'key' ? 'ssh_private_key' : 'ssh_password'])) {
            abort(422, '缺少 SSH 凭据');
        }
        return $data;
    }

    private function encryptCredentials(array &$data, SecretCipher $cipher)
    {
        $changed = false;
        if (!empty($data['ssh_private_key'])) {
            $data['ssh_private_key_encrypted'] = $cipher->encrypt($data['ssh_private_key']);
            $data['ssh_password_encrypted'] = null;
            $changed = true;
        }
        if (!empty($data['ssh_password'])) {
            $data['ssh_password_encrypted'] = $cipher->encrypt($data['ssh_password']);
            $data['ssh_private_key_encrypted'] = null;
            $changed = true;
        }
        unset($data['ssh_private_key'], $data['ssh_password']);
        if ($changed) $data['ssh_credential_key_version'] = $cipher->version();
    }
}
