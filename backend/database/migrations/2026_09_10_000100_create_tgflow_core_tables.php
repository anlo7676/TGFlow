<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateTgflowCoreTables extends Migration
{
    public function up()
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->text('totp_secret_encrypted')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->string('role', 32)->default('viewer');
            $table->string('status', 24)->default('active');
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('node_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_cents');
            $table->char('currency', 3)->default('CNY');
            $table->unsignedBigInteger('traffic_bytes');
            $table->unsignedInteger('duration_days');
            $table->unsignedInteger('device_limit')->nullable();
            $table->unsignedInteger('connection_limit')->nullable();
            $table->unsignedInteger('speed_limit_mbps')->nullable();
            $table->foreignId('node_group_id')->constrained()->restrictOnDelete();
            $table->string('reset_mode', 16)->default('none');
            $table->integer('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('proxy_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('host');
            $table->string('ipv4', 45)->nullable();
            $table->string('ipv6', 45)->nullable();
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_username', 64);
            $table->string('ssh_auth_type', 16);
            $table->text('ssh_private_key_encrypted')->nullable();
            $table->text('ssh_password_encrypted')->nullable();
            $table->string('ssh_credential_key_version')->nullable();
            $table->string('ssh_host_fingerprint');
            $table->string('os_name')->nullable();
            $table->string('os_version')->nullable();
            $table->string('architecture')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('proxy_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proxy_server_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('region', 64);
            $table->char('country_code', 2);
            $table->string('public_host');
            $table->unsignedSmallInteger('public_port');
            $table->string('fake_tls_domain')->nullable();
            $table->string('engine', 24)->default('mtproxymax');
            $table->string('engine_version')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('weight')->default(100);
            $table->timestamp('last_traffic_sync_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->timestamps();
        });

        Schema::create('node_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('proxy_node_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['node_group_id', 'proxy_node_id']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 32)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            $table->json('plan_snapshot');
            $table->string('status', 24)->default('pending')->index();
            $table->string('payment_method')->default('epay');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('provider', 24)->default('epay');
            $table->string('trade_no')->nullable();
            $table->string('provider_trade_no')->nullable();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('status', 24)->default('created');
            $table->json('request_payload_redacted')->nullable();
            $table->json('callback_payload_redacted')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_trade_no']);
            $table->index(['order_id', 'status']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('traffic_limit_bytes');
            $table->unsignedBigInteger('traffic_used_bytes')->default(0);
            $table->unsignedBigInteger('bonus_traffic_bytes')->default(0);
            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedBigInteger('desired_state_version')->default(0);
            $table->unsignedInteger('device_limit')->nullable();
            $table->unsignedInteger('connection_limit')->nullable();
            $table->unsignedInteger('speed_limit_mbps')->nullable();
            $table->json('entitlement_snapshot');
            $table->timestamps();
        });

        Schema::create('subscription_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('proxy_node_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->timestamps();
            $table->unique(['subscription_id', 'proxy_node_id']);
        });

        Schema::create('proxy_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('proxy_node_id')->constrained()->restrictOnDelete();
            $table->string('label');
            $table->text('secret_encrypted');
            $table->string('secret_key_version');
            $table->string('remote_identifier')->nullable();
            $table->unsignedBigInteger('allocated_quota_bytes')->default(0);
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('applied_state_version')->default(0);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'proxy_node_id']);
            $table->unique(['proxy_node_id', 'remote_identifier']);
        });

        Schema::create('node_traffic_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('proxy_node_id')->constrained()->restrictOnDelete();
            $table->foreignId('proxy_secret_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('counter_epoch');
            $table->unsignedBigInteger('source_sequence')->nullable();
            $table->unsignedBigInteger('total_bytes');
            $table->unsignedBigInteger('last_total_bytes');
            $table->dateTime('reported_at');
            $table->timestamps();
            $table->unique(['proxy_secret_id', 'counter_epoch']);
        });

        Schema::create('traffic_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('proxy_node_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('bytes');
            $table->string('direction', 16)->nullable();
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->string('observation_id', 96)->unique();
            $table->unsignedBigInteger('counter_epoch');
            $table->unsignedBigInteger('source_sequence')->nullable();
            $table->timestamps();
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_type');
            $table->json('payload');
            $table->dateTime('available_at');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['published_at', 'available_at']);
        });

        Schema::create('processed_telegram_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->dateTime('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('status', 24)->default('received');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('object_type');
            $table->unsignedBigInteger('object_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proxy_server_id')->constrained()->restrictOnDelete();
            $table->foreignId('proxy_node_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 24);
            $table->string('status', 24)->default('pending')->index();
            $table->longText('logs')->nullable();
            $table->boolean('logs_truncated')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            foreach (['traffic_80', 'traffic_90', 'traffic_95', 'traffic_100', 'expiry_7d', 'expiry_3d', 'expiry_1d', 'expiry_expired'] as $column) {
                $table->boolean($column)->default(true);
            }
            $table->timestamps();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('dedupe_key')->unique();
            $table->timestamp('sent_at')->nullable();
            $table->json('payload');
        });

        Schema::create('subscription_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->string('type', 24);
            $table->bigInteger('value');
            $table->string('reason', 500);
            $table->foreignId('admin_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->boolean('encrypted')->default(false);
            $table->timestamp('updated_at')->useCurrent();
        });

        $this->addChecks();
    }

    private function addChecks()
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $raw = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        preg_match('/^[0-9]+\.[0-9]+\.[0-9]+/', $raw, $match);
        if (!isset($match[0]) || version_compare($match[0], '8.0.16', '<')) {
            return;
        }
        DB::statement('ALTER TABLE plans ADD CONSTRAINT chk_plans_limits CHECK (price_cents >= 0 AND traffic_bytes >= 0 AND duration_days >= 1)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT chk_orders_amount CHECK (amount_cents >= 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount_cents >= 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_trade CHECK (status <> 'success' OR (provider_trade_no IS NOT NULL AND CHAR_LENGTH(TRIM(provider_trade_no)) > 0))");
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT chk_subscription_values CHECK (traffic_limit_bytes >= 0 AND traffic_used_bytes >= 0 AND bonus_traffic_bytes >= 0 AND expires_at >= started_at)');
        DB::statement('ALTER TABLE node_traffic_counters ADD CONSTRAINT chk_traffic_counters CHECK (total_bytes >= 0 AND last_total_bytes >= 0)');
        DB::statement('ALTER TABLE traffic_usage_logs ADD CONSTRAINT chk_traffic_usage CHECK (bytes >= 0 AND period_end >= period_start)');
    }

    public function down()
    {
        foreach (['system_settings', 'subscription_adjustments', 'notification_logs', 'notification_settings', 'deployments', 'audit_logs', 'processed_telegram_updates', 'outbox_events', 'traffic_usage_logs', 'node_traffic_counters', 'proxy_secrets', 'subscription_nodes', 'subscriptions', 'payments', 'orders', 'node_group_members', 'proxy_nodes', 'proxy_servers', 'plans', 'node_groups', 'admins'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
