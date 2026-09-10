<?php

namespace App\Http\Middleware;

use Closure;

class RequireAdminRole
{
    private const RANK = [
        'viewer' => 10,
        'support' => 20,
        'finance' => 30,
        'operation' => 40,
        'super_admin' => 50,
    ];

    public function handle($request, Closure $next, ...$roles)
    {
        $admin = $request->user();
        abort_unless($admin && $admin->status === 'active', 403);
        abort_unless(in_array($admin->role, $roles, true), 403, '当前角色无权执行此操作');
        return $next($request);
    }
}
