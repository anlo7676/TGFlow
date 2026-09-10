<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Security\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request, TotpService $totp)
    {
        $data = $request->validate(['username' => 'required|string|max:255', 'password' => 'required|string|max:1024', 'totp_code' => 'nullable|string|size:6']);
        $admin = Admin::where('username', $data['username'])->where('status', 'active')->first();
        if (!$admin || !Hash::check($data['password'], $admin->password_hash)) {
            abort(401, '用户名或密码错误');
        }
        if (config('security.admin_totp_required') && !$admin->totp_secret_encrypted) abort(403, '当前环境要求管理员启用 2FA');
        if ($admin->totp_secret_encrypted && !$totp->verify($totp->decrypt($admin->totp_secret_encrypted), isset($data['totp_code']) ? $data['totp_code'] : '')) abort(401, '两步验证码错误');
        $admin->update(['last_login_ip' => $request->ip(), 'last_login_at' => now()]);
        return response()->json(['token' => $admin->createToken('admin-panel')->plainTextToken, 'admin' => $admin]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->noContent();
    }
}
