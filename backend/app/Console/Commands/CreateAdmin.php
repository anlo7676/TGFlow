<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\Security\TotpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {username : 管理员用户名}
        {email : 管理员邮箱}
        {--role=super_admin : 管理员角色}
        {--password= : 非交互模式使用的密码}
        {--without-totp : 暂不启用两步验证}';
    protected $description = '创建启用 TOTP 两步验证的管理员';

    public function handle(TotpService $totp)
    {
        $role = $this->option('role');
        if (!in_array($role, ['super_admin', 'operation', 'finance', 'support', 'viewer'], true)) { $this->error('角色无效'); return 1; }
        if (Admin::where('username', $this->argument('username'))->orWhere('email', $this->argument('email'))->exists()) {
            $this->info('管理员已存在，跳过创建。');
            return 0;
        }
        $password = $this->option('password') ?: $this->secret('请输入至少 12 位管理员密码');
        if (strlen((string) $password) < 12) { $this->error('密码过短'); return 1; }
        $withoutTotp = (bool) $this->option('without-totp');
        $secret = $withoutTotp ? null : $totp->generateSecret();
        Admin::create([
            'username' => $this->argument('username'),
            'email' => $this->argument('email'),
            'password_hash' => Hash::make($password),
            'role' => $role,
            'status' => 'active',
            'totp_secret_encrypted' => $secret ? $totp->encrypt($secret) : null,
            'totp_confirmed_at' => $secret ? now() : null,
        ]);
        if ($secret) {
            $this->warn('请立即将以下 TOTP Secret 加入验证器；它不会再次显示：');
            $this->line($secret);
        } else {
            $this->warn('管理员已创建但未启用两步验证，请在正式公网运行前启用。');
        }
        return 0;
    }
}
