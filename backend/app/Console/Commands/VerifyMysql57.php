<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyMysql57 extends Command
{
    protected $signature = 'db:verify-mysql57';
    protected $description = '验证 MySQL 5.7.44、严格模式、InnoDB 与完整性触发器';

    public function handle()
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->error('当前连接不是 MySQL');
            return 1;
        }

        $settings = DB::selectOne('SELECT VERSION() AS version, @@SESSION.sql_mode AS sql_mode, @@default_storage_engine AS storage_engine, @@character_set_database AS charset_name, @@collation_database AS collation_name');
        $checks = [
            ['MySQL 版本', strpos($settings->version, '5.7.44') === 0, $settings->version],
            ['严格 SQL 模式', strpos($settings->sql_mode, 'STRICT_TRANS_TABLES') !== false || strpos($settings->sql_mode, 'STRICT_ALL_TABLES') !== false, $settings->sql_mode],
            ['默认存储引擎', strcasecmp($settings->storage_engine, 'InnoDB') === 0, $settings->storage_engine],
            ['数据库字符集', strcasecmp($settings->charset_name, 'utf8mb4') === 0, $settings->charset_name],
        ];

        $triggerCount = (int) DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', 'like', 'tgflow_guard_%')
            ->count();
        $checks[] = ['完整性触发器', $triggerCount === 12, $triggerCount.'/12'];

        $failed = false;
        foreach ($checks as $check) {
            $this->line(($check[1] ? '<info>通过</info>' : '<error>失败</error>').' '.$check[0].'：'.$check[2]);
            if (!$check[1]) $failed = true;
        }
        if ($failed) {
            $this->error('MySQL 5.7.44 兼容性检查未通过');
            return 1;
        }
        $this->info('MySQL 5.7.44 兼容性检查通过');
        return 0;
    }
}
