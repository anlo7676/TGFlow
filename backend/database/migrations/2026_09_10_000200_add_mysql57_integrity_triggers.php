<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddMysql57IntegrityTriggers extends Migration
{
    private const PREFIX = 'tgflow_guard_';

    public function up()
    {
        if (!$this->needsTriggers()) {
            return;
        }

        $this->create('plans', 'insert',
            "IF NEW.price_cents < 0 OR NEW.traffic_bytes < 0 OR NEW.duration_days < 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid plan limits'; END IF;");
        $this->create('plans', 'update',
            "IF NEW.price_cents < 0 OR NEW.traffic_bytes < 0 OR NEW.duration_days < 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid plan limits'; END IF;");

        $this->create('orders', 'insert',
            "IF NEW.amount_cents < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order amount must be non-negative'; END IF;");
        $this->create('orders', 'update',
            "IF NEW.amount_cents < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order amount must be non-negative'; END IF;");

        $paymentGuard = "IF NEW.amount_cents < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment amount must be non-negative'; END IF; "
            ."IF NEW.status = 'success' AND (NEW.provider_trade_no IS NULL OR CHAR_LENGTH(TRIM(NEW.provider_trade_no)) = 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'successful payment requires provider trade number'; END IF;";
        $this->create('payments', 'insert', $paymentGuard);
        $this->create('payments', 'update', $paymentGuard);

        $subscriptionGuard = "IF NEW.traffic_limit_bytes < 0 OR NEW.traffic_used_bytes < 0 OR NEW.bonus_traffic_bytes < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'subscription traffic must be non-negative'; END IF; "
            ."IF NEW.expires_at < NEW.started_at THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'subscription expiry precedes start'; END IF;";
        $this->create('subscriptions', 'insert', $subscriptionGuard);
        $this->create('subscriptions', 'update', $subscriptionGuard);

        $counterGuard = "IF NEW.total_bytes < 0 OR NEW.last_total_bytes < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'traffic counter must be non-negative'; END IF;";
        $this->create('node_traffic_counters', 'insert', $counterGuard);
        $this->create('node_traffic_counters', 'update', $counterGuard);

        $usageGuard = "IF NEW.bytes < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'traffic usage must be non-negative'; END IF; "
            ."IF NEW.period_end < NEW.period_start THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'traffic period is invalid'; END IF;";
        $this->create('traffic_usage_logs', 'insert', $usageGuard);
        $this->create('traffic_usage_logs', 'update', $usageGuard);
    }

    public function down()
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (['plans', 'orders', 'payments', 'subscriptions', 'node_traffic_counters', 'traffic_usage_logs'] as $table) {
            foreach (['insert', 'update'] as $event) {
                DB::unprepared('DROP TRIGGER IF EXISTS `'.$this->name($table, $event).'`');
            }
        }
    }

    private function create($table, $event, $body)
    {
        $name = $this->name($table, $event);
        DB::unprepared("CREATE TRIGGER `{$name}` BEFORE ".strtoupper($event)." ON `{$table}` FOR EACH ROW BEGIN {$body} END");
    }

    private function name($table, $event)
    {
        return self::PREFIX.$table.'_before_'.$event;
    }

    private function needsTriggers()
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }
        $raw = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        if (stripos($raw, 'mariadb') !== false) {
            throw new RuntimeException('当前数据库是 MariaDB；本项目的兼容目标是 MySQL 5.7.44');
        }
        preg_match('/^[0-9]+\.[0-9]+\.[0-9]+/', $raw, $match);
        $version = isset($match[0]) ? $match[0] : '0.0.0';
        if (version_compare($version, '5.7.44', '<')) {
            throw new RuntimeException('MySQL 版本不得低于 5.7.44，当前版本：'.$version);
        }
        return version_compare($version, '8.0.16', '<');
    }
}
