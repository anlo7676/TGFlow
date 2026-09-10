<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProxyNode;
use Illuminate\Http\Request;

class NodeController extends Controller
{
    public function index()
    {
        return ProxyNode::with('server:id,name,status')->latest()->paginate(100);
    }

    public function store(Request $request)
    {
        return response()->json(ProxyNode::create($this->validated($request)), 201);
    }

    public function update(Request $request, ProxyNode $node)
    {
        $node->update($this->validated($request));
        return $node->fresh();
    }

    private function validated(Request $request)
    {
        return $request->validate([
            'proxy_server_id' => 'required|exists:proxy_servers,id', 'name' => 'required|string|max:255',
            'region' => ['required', 'string', 'max:64', 'regex:/^[\pL\pN _.-]+$/u'], 'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'public_host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.:-]+$/'], 'public_port' => 'required|integer|min:1|max:65535',
            'fake_tls_domain' => ['nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/'],
            'engine' => 'required|in:mtproxymax', 'status' => 'required|in:pending,deploying,online,offline,maintenance,error,disabled',
            'max_users' => 'nullable|integer|min:1', 'weight' => 'integer|min:1|max:1000',
        ]);
    }
}
