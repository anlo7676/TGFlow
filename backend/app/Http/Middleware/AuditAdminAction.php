<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

class AuditAdminAction
{
    public function handle($request, Closure $next)
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->write($request, method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500);
            throw $exception;
        }
        $this->write($request, $response->getStatusCode());
        return $response;
    }

    private function write($request, $status)
    {
        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            DB::table('audit_logs')->insert([
                'admin_id' => optional($request->user())->id,
                'action' => $request->method().' '.$request->route()->uri(),
                'object_type' => 'admin_api',
                'object_id' => $this->routeObjectId($request),
                'before' => null,
                'after' => json_encode(['http_status' => $status]),
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
            ]);
        }
    }

    private function routeObjectId($request)
    {
        foreach (['subscription', 'server', 'node', 'plan'] as $key) {
            $value = $request->route($key);
            if ($value) return is_object($value) ? $value->id : (int) $value;
        }
        return null;
    }
}
