# Telegram MTProto Proxy 商业管理平台开发规格 v1.0

> 安全修订：2026-09-10。本文已将流量总账、支付 Outbox、Secret 存储、Webhook、SSH 控制面、密钥备份和基础 RBAC 的安全约束纳入 MVP；这些要求属于验收基线，不得作为后续优化推迟。

## 1. 项目目标

开发一套 Telegram MTProto Proxy 商业化管理系统。

系统由以下部分组成：

1. Web 管理后台
2. API / Billing Backend
3. Telegram 用户机器人
4. EPay 支付模块
5. Proxy Node Controller
6. MTProxyMax / telemt 代理节点
7. 全局流量计费系统
8. 定时任务与通知系统

最终达到：

- 管理员可以在网页后台添加多台 VPS。
- 后台保存 VPS SSH 登录信息及 SSH 私钥。
- 点击按钮即可通过 SSH 自动部署 MTProxyMax/telemt。
- 一套后台可以管理几十甚至数百个代理节点。
- 后台可以建立套餐。
- 套餐可以设置价格、流量、有效期、节点组、设备限制等。
- Telegram 用户可以直接通过机器人查看套餐并下单。
- 用户付款时跳转到 EPay 收银台。
- EPay 异步通知成功后自动开通服务。
- 每个用户在不同服务器获得独立代理 Secret。
- 同一个套餐下的多台服务器共用一个总流量额度。
- 总流量耗尽后，用户在全部代理服务器立即/近实时禁用。
- 套餐到期后全部节点自动禁用。
- 用户续费或增加流量后自动恢复节点。
- Telegram Bot 可以查询流量、有效期、订单和节点。
- Telegram Bot 可以发送流量不足和即将到期提醒。
- 后台可以人工禁用、恢复、重置流量、延期以及补流量。

---

# 2. 基本技术架构

推荐：

```text
Frontend:
Vue 3
TypeScript
Element Plus
Vite

Backend:
Laravel
PHP 8.3/8.4

Database:
MySQL 8

Cache:
Redis

Queue:
Laravel Queue + Horizon

Scheduler:
Laravel Scheduler

Proxy:
MTProxyMax
telemt

Server Deployment:
SSH

Bot:
Telegram Bot API
Webhook 模式

Payment:
EPay

Metrics:
Prometheus / MTProxyMax stats

Web Server:
Nginx

Production:
Docker Compose 或标准 Linux 部署
```

不建议：

```text
直接把 V2Board 原代码大规模修改成 MTProxy Panel
```

建议：

```text
参考 V2Board：
User
Plan
Order
Payment
Coupon
Admin
Queue

重新设计：
ProxyServer
ProxyNode
ProxyIdentity
ProxySecret
Traffic
Subscription
Deployment
```

---

# 3. 总体架构

```text
                           Internet
                              │
                 ┌────────────┴────────────┐
                 │                         │
              Admin Web               Telegram Bot
                 │                         │
                 └────────────┬────────────┘
                              │
                       Laravel Backend
                              │
     ┌────────────────────────┼────────────────────────┐
     │                        │                        │
   MySQL                    Redis                    Queue
     │                        │                        │
 用户/套餐                  流量/锁                 SSH任务
 订单/订阅                  状态缓存                 同步任务
     │                        │                        │
     └────────────────────────┼────────────────────────┘
                              │
                      Node Controller
                              │
                       SSH / HTTPS API
                              │
          ┌───────────────────┼──────────────────────┐
          │                   │                      │
       Tokyo-01          Singapore-01             US-01
          │                   │                      │
      MTProxyMax          MTProxyMax              MTProxyMax
          │                   │                      │
        telemt              telemt                  telemt
```

---

# 4. 核心领域模型

必须明确区分：

```text
User
↓
Subscription
↓
Subscription Nodes
↓
Proxy Secrets
```

一个 Telegram 用户可以有多个订单。

一个订单支付成功后生成一个 Subscription。

一个 Subscription 对应一个套餐。

一个 Subscription 可以使用多个 Proxy Node。

每个 Proxy Node 上为该 Subscription 建立一个 Secret。

但所有 Secret：

```text
共用同一个 Subscription Traffic Pool
```

---

# 5. 数据库设计

## 5.1 users

```text
users

id
telegram_id BIGINT UNIQUE
telegram_username VARCHAR NULL
telegram_first_name VARCHAR NULL
telegram_language VARCHAR NULL

email VARCHAR NULL
password_hash VARCHAR NULL

status ENUM(
 active,
 disabled,
 banned
)

created_at
updated_at
last_login_at
```

以 `telegram_id` 作为 Bot 用户主要身份。

不要以 Telegram username 作为唯一标识。

---

# 5.2 admins

```text
admins

id
username
email
password_hash
role
status
last_login_ip
last_login_at
created_at
updated_at
```

MVP 必须实现最小权限控制，不能只校验“是否已登录”。至少包含：

```text
super_admin
finance
operation
support
viewer
```

每个 Admin API 和后台操作必须显式声明所需权限；默认拒绝。部署、卸载、SSH 凭据替换、支付配置、Secret 重建等高风险操作仅允许 `super_admin` 或明确授权的 `operation` 角色执行。

Phase 2 可以增加自定义角色、细粒度权限组合等复杂 RBAC，但不能推迟 MVP 的基础授权检查。

---

# 5.3 plans

```text
plans

id

name
description

price_cents BIGINT
currency CHAR(3)

traffic_bytes BIGINT
duration_days INT

device_limit INT NULL
connection_limit INT NULL
speed_limit_mbps INT NULL

node_group_id BIGINT

reset_mode ENUM(
 none,
 monthly
)

sort INT
enabled BOOLEAN

created_at
updated_at
```

例如：

```text
Plan:
基础套餐

Price:
15.00

Traffic:
107374182400

Duration:
30

Node Group:
1
```

代表：

```text
100GB
30天
¥15
Standard 节点组
```

---

# 5.4 node_groups

```text
node_groups

id
name
description
enabled
created_at
updated_at
```

例如：

```text
Standard
Premium
Asia
Global
```

---

# 5.5 node_group_members

```text
node_group_members

id
node_group_id
proxy_node_id
created_at
```

这样套餐无需直接绑定服务器。

套餐：

```text
Plan
   ↓
Node Group
   ↓
Proxy Nodes
```

---

# 5.6 proxy_servers

代表物理 VPS。

```text
proxy_servers

id

name
host
ipv4
ipv6 NULL

ssh_port
ssh_username

ssh_auth_type ENUM(
 key,
 password
)

ssh_private_key_encrypted TEXT NULL
ssh_password_encrypted TEXT NULL
ssh_credential_key_version VARCHAR NULL

ssh_host_fingerprint VARCHAR NULL

os_name
os_version
architecture

status ENUM(
 pending,
 online,
 offline,
 error,
 disabled
)

last_seen_at
last_error

created_at
updated_at
```

SSH 私钥必须使用带认证的加密方式加密，并记录 `key_version` 以支持轮换。

禁止：

```text
数据库明文 SSH Key
```

必须：

```text
AES-256-GCM
```

主密钥通过专用 Secret Manager / KMS 或独立的 `SSH_MASTER_KEY` 提供。

禁止使用同时负责 Session、Cookie 等用途的 `APP_KEY` 直接加密 SSH 凭据，也禁止将主密钥与数据库备份存放在同一个备份包中。

主密钥不得存入数据库；必须支持轮换、旧版本解密和访问审计。开发环境可以使用独立环境变量，生产环境优先使用 KMS/Vault。

示例：

```text
SSH_MASTER_KEY
SSH_MASTER_KEY_VERSION
```

---

# 5.7 proxy_nodes

一台 VPS 通常对应一个 Proxy Node。

```text
proxy_nodes

id

proxy_server_id

name
region
country_code

public_host
public_port

fake_tls_domain NULL

engine ENUM(
 mtproxymax
)

engine_version NULL

status ENUM(
 pending,
 deploying,
 online,
 offline,
 maintenance,
 error,
 disabled
)

max_users NULL
weight DEFAULT 100

last_traffic_sync_at
last_health_check_at

created_at
updated_at
```

---

# 5.8 deployments

记录所有服务器操作。

```text
deployments

id
proxy_server_id
proxy_node_id NULL

type ENUM(
 install,
 update,
 restart,
 uninstall,
 repair
)

status ENUM(
 pending,
 running,
 success,
 failed
)

logs LONGTEXT
logs_truncated BOOLEAN DEFAULT FALSE
started_at
finished_at
created_at
```

后台必须能查看部署日志。

部署日志必须：

```text
写入前脱敏
限制单任务最大尺寸
过滤 ANSI / 终端控制字符
在 Web 页面按纯文本转义显示
设置保留期限
记录查看审计
```

---

# 5.9 orders

```text
orders

id

order_no UNIQUE
user_id
plan_id

amount_cents BIGINT
currency CHAR(3)
plan_snapshot JSON

status ENUM(
 pending,
 paying,
 paid,
 cancelled,
 expired,
 refunded
)

payment_method
payment_id NULL

created_at
paid_at NULL
expired_at NULL
updated_at
```

订单号示例：

```text
TG202609101234567891
```

必须由服务器生成且保证唯一。

---

# 5.10 payments

```text
payments

id

order_id

provider ENUM(
 epay
)

trade_no NULL
provider_trade_no NULL

amount_cents BIGINT
currency CHAR(3)

status ENUM(
 created,
 pending,
 success,
 failed,
 refunded
)

request_payload_redacted JSON NULL
callback_payload_redacted JSON NULL

created_at
paid_at NULL
updated_at
```

数据库约束：

```text
UNIQUE(provider, provider_trade_no)
INDEX(order_id, status)
CHECK(amount_cents >= 0)
```

请求和回调原文只用于内存中的验签。持久化前必须使用字段白名单并脱敏，禁止保存签名、密钥、完整支付账户信息或未经限制的原始请求体。

---

# 5.11 subscriptions

这是整个系统最核心的表。

```text
subscriptions

id

user_id
plan_id
order_id NULL UNIQUE

traffic_limit_bytes BIGINT
traffic_used_bytes BIGINT DEFAULT 0

bonus_traffic_bytes BIGINT DEFAULT 0

started_at
expires_at

status ENUM(
 pending,
 active,
 traffic_exhausted,
 expired,
 manually_disabled,
 cancelled
)

desired_state_version BIGINT DEFAULT 0

device_limit NULL
connection_limit NULL
speed_limit_mbps NULL

entitlement_snapshot JSON

created_at
updated_at
```

`entitlement_snapshot` 保存下单时固化的套餐版本、流量、时长、设备/连接/速度限制和计费规则。支付完成后必须从订单快照创建订阅，禁止重新读取可能已被管理员修改的当前套餐权益。

实际可用流量：

```text
total_limit =
traffic_limit_bytes
+
bonus_traffic_bytes
```

剩余：

```text
remaining =
total_limit
-
traffic_used_bytes
```

---

# 5.12 subscription_nodes

定义该订阅可以使用哪些节点。

```text
subscription_nodes

id

subscription_id
proxy_node_id

status ENUM(
 pending,
 provisioning,
 active,
 disabled,
 error
)

created_at
updated_at
```

必须增加：

```text
UNIQUE(subscription_id, proxy_node_id)
FOREIGN KEY(subscription_id)
FOREIGN KEY(proxy_node_id)
```

---

# 5.13 proxy_secrets

每个节点对应一个用户 Secret。

```text
proxy_secrets

id

subscription_id
proxy_node_id

label
secret_encrypted
secret_key_version VARCHAR

remote_identifier NULL

status ENUM(
 pending,
 active,
 disabled,
 expired,
 revoked,
 error
)

applied_state_version BIGINT DEFAULT 0

last_sync_at
created_at
updated_at
```

必须增加：

```text
UNIQUE(subscription_id, proxy_node_id)
UNIQUE(proxy_node_id, remote_identifier)
```

禁止持久化包含完整 Secret 的 `proxy_url` 或 `telegram_proxy_url`。用户请求链接时，服务端在完成订阅归属和状态校验后临时解密 Secret、即时生成链接，响应使用 `Cache-Control: no-store`，且不得进入日志、缓存、异常上下文或分析系统。

label 推荐：

```text
sub_10001_user_123
```

不要直接暴露：

```text
用户真实名字
Telegram username
邮箱
```

给节点。

---

# 5.14 node_traffic_counters

记录每个节点的原始累计量。

```text
node_traffic_counters

id

subscription_id
proxy_node_id
proxy_secret_id

counter_epoch BIGINT
source_sequence BIGINT NULL

total_bytes

last_total_bytes

reported_at
created_at
updated_at
```

必须增加：

```text
UNIQUE(proxy_secret_id, counter_epoch)
CHECK(total_bytes >= 0)
CHECK(last_total_bytes >= 0)
```

---

# 5.15 traffic_usage_logs

保存增量流量流水。

```text
traffic_usage_logs

id

subscription_id
proxy_node_id

bytes
direction NULL

period_start
period_end

observation_id VARCHAR UNIQUE
counter_epoch BIGINT
source_sequence BIGINT NULL

created_at
```

`observation_id` 必须由节点身份、Secret、counter epoch 和采集序列稳定生成，使同一观测被重复投递时不会重复计费。

例如：

```text
subscription = 100
node = Tokyo
delta = 104857600
```

代表此次统计增加 100 MB。

---

# 5.16 notification_settings

```text
notification_settings

id
user_id

traffic_80 BOOLEAN
traffic_90 BOOLEAN
traffic_95 BOOLEAN
traffic_100 BOOLEAN

expiry_7d BOOLEAN
expiry_3d BOOLEAN
expiry_1d BOOLEAN
expiry_expired BOOLEAN

created_at
updated_at
```

---

# 5.17 notification_logs

避免通知重复发送。

```text
notification_logs

id
user_id
subscription_id

type

dedupe_key UNIQUE

sent_at
payload JSON
```

例如：

```text
traffic_80:subscription:123:202609
```

---

# 5.18 system_settings

```text
system_settings

key
value
encrypted BOOLEAN
updated_at
```

保存：

```text
BOT_USERNAME
EPAY_PID
EPAY_URL
PANEL_URL
traffic_sync_interval
health_check_interval
```

`BOT_TOKEN`、`EPAY_KEY`、SSH 主密钥等高价值 Secret 默认不得保存在 `system_settings`。生产环境必须由 Secret Manager / KMS 注入；如果部署条件确实只能保存在数据库中，必须使用独立主密钥进行带认证加密，并记录密钥版本、轮换时间和读取审计。

所有可配置 URL 必须按用途设置协议、域名和端口白名单。禁止管理员输入任意 URL 后由服务器直接请求。

---

# 5.19 数据库完整性约束

应用层检查不能替代数据库约束。所有迁移必须显式定义外键、删除策略、唯一索引和非负值检查，至少包括：

```text
admins.username UNIQUE
admins.email UNIQUE
node_group_members(node_group_id, proxy_node_id) UNIQUE
subscriptions.order_id UNIQUE
subscription_nodes(subscription_id, proxy_node_id) UNIQUE
proxy_secrets(subscription_id, proxy_node_id) UNIQUE
payments(provider, provider_trade_no) UNIQUE
traffic_usage_logs.observation_id UNIQUE

amount_cents >= 0
traffic_limit_bytes >= 0
traffic_used_bytes >= 0
bonus_traffic_bytes >= 0
expires_at >= started_at
```

财务、订阅、Secret 和流量流水默认禁止级联硬删除。需要删除用户、套餐或节点时使用状态迁移或软删除，并保留审计和历史引用。

---

# 5.20 幂等与 Outbox 表

```text
processed_telegram_updates

update_id BIGINT UNIQUE
received_at
processed_at NULL
status
```

```text
outbox_events

id UUID PRIMARY KEY
aggregate_type
aggregate_id
event_type
payload JSON
available_at
published_at NULL
attempts DEFAULT 0
last_error NULL
created_at
```

业务数据与 Outbox Event 必须在同一个 MySQL 事务中提交。Relay 允许重复投递，因此消费者仍需以 event id 和业务唯一约束保证幂等。

Outbox payload 必须使用最小字段白名单，只保存对象 ID、状态版本等引用，禁止放入 SSH Key、Proxy Secret、Bot Token、支付密钥或完整支付报文。

---

# 6. 套餐生命周期

完整生命周期：

```text
用户选择套餐
    ↓
创建订单
    ↓
pending
    ↓
生成 EPay 支付
    ↓
paying
    ↓
EPay callback
    ↓
验证成功
    ↓
paid
    ↓
创建 Subscription
    ↓
active
    ↓
给允许节点创建 Secret
```

到期：

```text
active
   ↓
expires_at <= NOW
   ↓
expired
   ↓
全部 Secret disable
```

流量耗尽：

```text
active
   ↓
traffic_used >= traffic_limit + bonus
   ↓
traffic_exhausted
   ↓
全部节点 disable
```

人工禁用：

```text
active
   ↓
管理员 Disable
   ↓
manually_disabled
```

续费后：

```text
expired
或者
traffic_exhausted
        ↓
支付成功
        ↓
根据续费规则更新
        ↓
active
        ↓
全部节点 enable
```

---

# 7. 续费策略

后台必须支持至少两种模式。

## 模式 A：新建订阅

套餐到期以后重新购买：

```text
创建新的 Subscription
```

旧订阅保留历史。

优点：

统计清晰。

推荐第一版采用。

## 模式 B：延长现有订阅

例如：

```text
剩余 5 天
用户买 30 天
```

变成：

```text
35 天
```

以及：

```text
剩余 10GB
购买 100GB
```

可以：

```text
剩余 110GB
```

第二阶段实现。

---

# 8. 流量计费核心逻辑

假设：

```text
User A

Subscription:
100GB
```

节点：

```text
Tokyo
Singapore
Los Angeles
```

实际使用：

```text
Tokyo        20GB
Singapore    30GB
Los Angeles  15GB
```

总使用：

```text
65GB
```

剩余：

```text
35GB
```

任何节点都不能拥有独立的：

```text
100GB
```

额度。

必须由中央：

```text
Subscription
```

作为唯一总账。

---

# 9. 流量统计算法

节点提供：

```text
累计 Traffic Counter
```

例如第一次：

```text
Tokyo:
10,000 MB
```

数据库：

```text
last_total = 9,900 MB
```

此次增加：

```text
100MB
```

计算：

```text
delta =
current_total
-
last_total
```

然后原子增加：

```text
subscription.traffic_used += delta
```

这里的“原子增加”必须与观测去重、counter 基线更新和流量流水写入处于同一个数据库事务中：

```text
BEGIN

INSERT traffic_usage_logs(observation_id, ...)
ON DUPLICATE → no-op

SELECT node_traffic_counter FOR UPDATE
校验 counter_epoch / source_sequence
计算非负 delta
更新 last_total_bytes
原子增加 subscriptions.traffic_used_bytes
若达到额度，条件更新 Subscription 状态和 desired_state_version
若状态发生改变，写入 DisableSubscriptionRequested Outbox

COMMIT
```

只有成功插入新的 `observation_id` 才允许增加订阅用量。任何 Job 重试、节点重复上报或网络超时重放都必须得到相同结果。

必须考虑：

```text
counter reset
server reboot
secret recreated
traffic reset
overflow
duplicate report
```

如果：

```text
current_total < last_total
```

不能产生负数。

认为：

```text
Counter Reset
```

必须切换到新的 `counter_epoch` 并重新建立基线，不能把新累计值直接全部计入，也不能修改旧 epoch 的基线。系统需要记录 `counter_reset` 审计事件并告警，因为重置与两次采集之间的流量可能无法恢复。

必须统一定义计费方向。若节点同时报告上传和下载，不得把已经包含双向流量的 total 与方向明细再次相加。

---

# 10. Redis 流量缓存设计

建议：

```text
quota:subscription:{id}
```

例如：

```text
quota:subscription:1001
```

内容：

```text
limit
used
remaining
status
updated_at
```

MySQL 的 `subscriptions.traffic_used_bytes` 和不可重复的 `traffic_usage_logs` 是唯一持久总账。Redis 只能保存可重建缓存，禁止成为唯一计费依据。

数据库事务提交后刷新或失效 Redis。缓存丢失时必须从数据库重建，不得把缓存中的旧值反向覆盖数据库。

然后判断：

```text
used >= limit
```

如果达到额度，必须在上述流量入账的同一个数据库事务内通过条件更新完成不可逆的期望状态转换：

```text
UPDATE subscriptions
SET status = traffic_exhausted,
    desired_state_version = desired_state_version + 1
WHERE id = ?
  AND status = active
  AND traffic_used_bytes >= traffic_limit_bytes + bonus_traffic_bytes
```

只有成功改变状态的 Worker 写入事务 Outbox，随后触发禁用任务。

随后：

```text
DisableSubscriptionJob
```

其他 Worker 不重复产生状态转换。节点禁用操作本身仍必须幂等，并携带 `desired_state_version`，拒绝执行比节点当前版本更旧的启用/禁用任务。

---

# 11. 多节点超额流量问题

不能保证完全 0 Byte 超额。

例如用户剩余：

```text
100MB
```

三个节点同时传输：

```text
Tokyo +80MB
SG +80MB
US +80MB
```

如果每 30 秒统计一次：

可能最终出现：

```text
超出 140MB
```

因此第一版目标建议：

```text
近实时限制
```

而不是：

```text
绝对零超额
```

建议：

```text
流量同步：
5~15 秒

接近额度：
1~5 秒
```

达到：

```text
80%
90%
95%
```

时动态缩短统计间隔。

---

# 12. 第二阶段严格额度模式

需要更严格时：

```text
Node Agent
   ↓
Central Quota API
```

每个节点申请：

```text
Traffic Token
```

例如：

```text
100MB
```

中央：

```text
remaining -= 100MB
```

节点只能继续消费被授权的 token。

剩余为 0：

```text
拒绝新 Token
```

这是 Phase 2。

MVP 不建议先做。

---

# 13. MTProxyMax 节点抽象层

后台不要到处直接执行：

```text
mtproxymax xxx
```

必须做 Adapter。

例如：

```text
interface ProxyEngine
{
    install();
    healthCheck();

    createUser();
    removeUser();

    enableUser();
    disableUser();

    setQuota();
    setExpiry();

    getUserTraffic();
    getUsers();

    restart();
}
```

实现：

```text
MTProxyMaxEngine
```

未来可以新增：

```text
OtherMTProxyEngine
```

不会影响业务层。

---

# 14. 节点部署流程

后台：

```text
服务器
→ 添加服务器
```

管理员填写：

```text
名称
IP
SSH端口
用户名
认证方式
Private Key / Password
Proxy Port
Fake TLS Domain
地区
```

所有字段必须在进入 SSH Adapter 前进行严格校验：

```text
host/IP：拒绝环回、链路本地、云元数据地址和未授权内网段
SSH/Proxy port：1~65535 且符合允许端口策略
username：仅允许预定义安全字符
Fake TLS Domain：必须是规范化域名，禁止 URL、空白和 Shell 元字符
region/country_code：枚举或长度受限
```

测试连接和部署接口仅允许高权限角色使用，并必须经过目标网段白名单和服务器端出口 ACL。防护必须在解析 DNS 后再次检查最终 IP，防止 DNS rebinding。

点击：

```text
测试连接
```

执行：

```text
1. SSH connect
2. host key verification
3. uname -a
4. cat /etc/os-release
5. uname -m
6. df -h
7. free -m
8. ss -lntp
9. curl public IP
10. 检查 sudo
```

成功：

```text
Connection OK
```

点击：

```text
部署节点
```

Queue：

```text
DeployProxyNodeJob
```

执行：

```text
SSH
 ↓
安装固定版本的 curl/docker/依赖
 ↓
从允许的 HTTPS 源下载固定版本 MTProxyMax
 ↓
校验 SHA-256 / 发布签名
 ↓
安装固定版本 telemt 并校验完整性
 ↓
设置端口
 ↓
设置 FakeTLS
 ↓
创建管理配置
 ↓
启动
 ↓
健康检查
 ↓
保存节点信息
 ↓
Online
```

禁止使用 `curl | sh`，禁止把表单输入直接拼接到 Shell 命令。SSH 命令应使用固定脚本、参数数组或可靠的 POSIX 参数转义，并为输入设置长度上限。

节点部署必须使用专用非 root 用户。sudo 仅允许执行经过审查的固定命令或 root-owned 脚本，不得授予任意 Shell。

---

# 15. SSH 安全要求

必须：

```text
Private Key 加密存储
Password 加密存储
```

敏感字段：

```text
后台列表不显示
API 不返回
日志不打印
Exception 不记录
```

必须验证：

```text
SSH Host Key Fingerprint
```

第一次：

```text
由管理员通过 VPS 控制台、云厂商 API 或其他带外可信渠道确认 fingerprint 后记录
```

以后：

```text
fingerprint 变化
→ 拒绝自动连接
→ 后台警告
```

禁止把首次 SSH 连接返回的 fingerprint 自动接受为可信值；这种 TOFU 只能发现后续变化，不能阻止首次连接 MITM。

---

# 16. 部署任务必须异步

禁止 Web 请求直接执行长 SSH。

正确：

```text
POST /admin/servers/1/deploy
        ↓
创建 Deployment
        ↓
Queue
        ↓
DeployProxyNodeJob
```

HTTP 立即：

```json
{
  "deployment_id": 123,
  "status": "pending"
}
```

前端轮询：

```text
GET /admin/deployments/123
```

或者 WebSocket/SSE。

---

# 17. Telegram Bot 功能

主菜单：

```text
欢迎使用 XXX Proxy

🛒 购买套餐
🌐 我的节点

📊 流量查询
📦 我的套餐

💳 我的订单
⚙️ 通知设置

❓ 帮助
```

---

# 18. Bot /start

第一次：

```text
Telegram Update
↓
查 telegram_id
↓
不存在
↓
创建 User
```

已有：

```text
更新 username / first_name
```

禁止因为 Telegram username 改变创建新账户。

---

# 19. Bot 购买套餐

点击：

```text
🛒 购买套餐
```

返回：

```text
请选择套餐

100GB / 30天 / ¥15
300GB / 30天 / ¥30
1TB / 90天 / ¥80
```

按钮 callback：

```text
plan:1
plan:2
plan:3
```

确认：

```text
套餐：100GB / 30天
价格：¥15

[立即购买]
[返回]
```

---

# 20. 创建订单

点击：

```text
立即购买
```

调用：

```text
POST internal/orders
```

生成：

```text
order_no
```

例如：

```text
TG260910ABCDEFG
```

创建：

```text
status = pending
```

订单金额和权益必须完全由服务端根据当前可购买 Plan 生成，禁止接受客户端传入的价格、币种、流量或时长。创建订单时在同一事务内固化 `amount_cents`、`currency` 和 `plan_snapshot`；后续支付和开通均以该快照为准。

同一 Telegram Update / callback 的重试不得重复创建订单。应使用处理过的 `update_id` 去重，并可为短时间内相同用户、套餐和幂等键复用已有 pending 订单。

然后调用 EPay。

---

# 21. EPay 接口

Payment Adapter：

```text
interface PaymentGateway
{
    createPayment(Order $order);
    verifyNotify(Request $request);
    queryPayment(Order $order);
}
```

实现：

```text
EpayGateway
```

配置：

```text
EPAY_BASE_URL
EPAY_PID
EPAY_KEY
EPAY_NOTIFY_URL
EPAY_RETURN_URL
```

具体字段应针对最终所用 EPay 服务商配置，不能把某个第三方 EPay 实现的签名细节硬编码到业务层。

---

# 22. 支付流程

```text
Bot
 ↓
Create Order
 ↓
EPayGateway
 ↓
payment_url
 ↓
Telegram Inline Button
```

例如：

```text
订单：TGxxxxx
套餐：100GB / 30天
金额：¥15

[💳 前往支付]
```

按钮：

```text
HTTPS EPay URL
```

---

# 23. EPay Callback

例如：

```text
POST /api/payment/epay/notify
```

必须：

```text
1 对原始请求字节按该服务商的规范验签
2 使用常量时间比较签名
3 校验商户号和支付渠道
4 校验订单号
5 校验 amount_cents 和 currency
6 校验支付状态
7 校验 provider_trade_no 唯一
8 服务商支持时校验 timestamp / nonce，限制重放窗口
9 幂等处理
```

禁止：

```text
只根据 return_url 判断支付成功
```

必须以：

```text
服务器异步 notify
```

为准。

---

# 24. 支付幂等

同一订单可能收到：

```text
3次
10次
几十次
```

Callback。

必须保证：

```text
Order pending
→ paid
```

只执行一次。

推荐：

```text
DB Transaction
+
SELECT FOR UPDATE
```

伪逻辑：

```text
parse bounded raw request
verify signature / merchant / provider status

BEGIN

lock order

if order.status == paid:
    return success

verify order_no / amount_cents / currency against locked order snapshot

insert or upsert payment by UNIQUE(provider, provider_trade_no)

order.status = paid

create subscription

insert outbox event: SubscriptionProvisionRequested

COMMIT
```

`success` 状态的 Payment 必须具有非空 `provider_trade_no`。任何唯一交易号已绑定到其他订单的回调都必须拒绝并告警，不能重新绑定。

Outbox Relay 在事务提交后发送：

```text
ProvisionSubscriptionJob
```

禁止依赖“提交后立即 dispatch”作为唯一保障，因为进程可能在提交与投递之间崩溃。Outbox Relay 必须可重试；即使消息重复发送，Provision Job 也只能产生一个 Subscription 和一组唯一 Secret。

---

# 25. 开通 Subscription

支付完成：

```text
OrderPaid
 ↓
CreateSubscription
 ↓
ProvisionSubscriptionJob
```

从订单的不可变 `plan_snapshot` 复制：

```text
traffic_limit
duration
device limit
speed limit
node group policy
```

禁止在支付成功时重新读取当前 Plan 决定用户权益。套餐修改只能影响新创建的订单。

计算：

```text
started_at = now

expires_at =
now + duration_days
```

---

# 26. 创建代理节点 Secret

根据：

```text
Subscription.entitlement_snapshot.node_group policy
```

获取：

```text
Tokyo
Singapore
US
```

为每个节点：

```text
CreateProxySecretJob
```

生成：

```text
secret
label
expiry
limits
```

成功后：

```text
proxy_secrets.status = active
```

---

# 27. Proxy URL

节点需要生成：

```text
server
port
secret
```

并组合：

```text
https://t.me/proxy?server=HOST&port=PORT&secret=SECRET
```

或：

```text
tg://proxy?server=HOST&port=PORT&secret=SECRET
```

Bot 推荐返回 HTTPS Telegram Proxy Link，更容易直接点击。

链接是 Bearer Credential，任何获得链接的人都可能使用代理。生成前必须重新校验用户身份、Subscription 归属、状态和 Secret 状态；完整链接仅在响应阶段存在，不持久化、不缓存、不进入日志。

后台必须提供单节点 Secret 吊销/轮换能力。轮换后旧 Secret 必须在节点侧确认失效，并记录审计事件。

---

# 28. 我的节点

Bot：

```text
🌐 我的节点
```

显示：

```text
🇯🇵 东京
🟢 在线
[连接代理]

🇸🇬 新加坡
🟢 在线
[连接代理]

🇺🇸 洛杉矶
🟢 在线
[连接代理]
```

如果：

```text
subscription.status != active
```

不要返回可使用 Secret。

显示：

```text
套餐已到期
```

或：

```text
流量已用尽
```

---

# 29. 流量查询

Bot：

```text
📊 流量查询
```

返回：

```text
📊 当前套餐

套餐：
100GB / 30天

已使用：
68.42 GB

剩余：
31.58 GB

使用比例：
68.42%

有效期：
2026-09-10
至
2026-10-10

剩余：
30天
```

可追加：

```text
节点统计

🇯🇵 东京       32GB
🇸🇬 新加坡     18GB
🇺🇸 洛杉矶     18.42GB
```

---

# 30. 通知

默认：

```text
80%
90%
95%
100%
```

流量提醒。

例如：

```text
⚠️ 流量提醒

您的套餐已经使用 90%。

套餐总流量：100GB
已使用：90GB
剩余：10GB

[购买套餐]
```

---

# 31. 到期提醒

后台可设置：

```text
7 days
3 days
1 day
expired
```

例如：

```text
⏰ 套餐即将到期

您的套餐将在 3 天后到期。

到期时间：
2026-10-10 12:30

剩余流量：
26.42GB

[续费套餐]
```

---

# 32. 流量耗尽

达到：

```text
used >= limit
```

立即：

```text
Subscription.status =
traffic_exhausted
```

发：

```text
DisableSubscriptionJob
```

对所有节点：

```text
disable secret
```

同时 Bot：

```text
⛔ 套餐流量已用尽

您的代理服务已经暂停。

[购买套餐]
```

---

# 33. 到期任务

Scheduler：

```text
每分钟
```

执行：

```text
subscriptions
where:
status = active
and
expires_at <= now
```

更新：

```text
expired
```

再调用：

```text
DisableSubscriptionJob
```

---

# 34. 节点健康检测

Scheduler：

```text
每 1 分钟
```

执行：

```text
NodeHealthCheckJob
```

检查：

```text
SSH/API 是否在线
Proxy Port
MTProxyMax Process
telemt Process
CPU
Memory
Disk
Load
Traffic Collector
```

状态：

```text
online
offline
error
maintenance
```

---

# 35. 用户节点健康策略

某节点离线：

```text
不要禁用 Subscription
```

只：

```text
node.status = offline
```

Bot 隐藏/标记：

```text
🔴 暂不可用
```

其他节点继续使用。

---

# 36. 管理后台菜单

```text
Dashboard

用户管理
套餐管理
订单管理
支付管理

服务器管理
节点管理
节点组

订阅管理
流量管理

部署任务
通知日志
审计日志

Telegram Bot
支付配置
系统设置
```

---

# 37. Dashboard

展示：

```text
今日收入
本月收入

用户总数
活跃订阅

总节点数
在线节点数
离线节点数

今日流量
本月流量

即将到期
流量即将耗尽

订单数量
支付成功率
```

---

# 38. 服务器管理页面

列表：

```text
名称
IP
地区
系统
CPU
RAM

SSH
Proxy

状态
最近在线
操作
```

操作：

```text
测试 SSH
部署
升级
重启 Proxy
维修
禁用
删除
查看日志
```

删除前：

```text
禁止服务器仍存在 active subscriptions
```

或要求先迁移。

---

# 39. 用户管理

列表：

```text
Telegram ID
Username
状态
当前套餐
流量
到期时间
注册时间
```

操作：

```text
查看
禁用
恢复
赠送流量
延期
重置流量
新建套餐
终止套餐
```

---

# 40. Subscription 详情

展示：

```text
套餐
状态

总流量
已用
剩余

开始时间
到期时间

节点列表

Tokyo
secret status
traffic

Singapore
secret status
traffic
```

后台按钮：

```text
Disable All
Enable All
Sync Nodes
Reset Traffic
Add Traffic
Extend Expiry
Rebuild Secrets
```

---

# 41. 套餐管理

支持：

```text
名称
售价
币种

流量
有效期

节点组

设备限制
连接限制
速度限制

显示/隐藏
排序
```

禁止用户购买 disabled plan。

---

# 42. Payment 后台

需要：

```text
订单号
用户
金额
渠道
交易号
创建时间
支付时间
状态
```

查看：

```text
EPay Request
EPay Callback
```

敏感字段要脱敏。

---

# 43. API 设计

Admin：

```text
POST   /api/admin/login

GET    /api/admin/dashboard

GET    /api/admin/users
GET    /api/admin/users/{id}

GET    /api/admin/plans
POST   /api/admin/plans
PUT    /api/admin/plans/{id}
DELETE /api/admin/plans/{id}

GET    /api/admin/servers
POST   /api/admin/servers
PUT    /api/admin/servers/{id}

POST   /api/admin/servers/{id}/test
POST   /api/admin/servers/{id}/deploy
POST   /api/admin/servers/{id}/restart

GET    /api/admin/nodes

GET    /api/admin/subscriptions
GET    /api/admin/subscriptions/{id}

POST   /api/admin/subscriptions/{id}/disable
POST   /api/admin/subscriptions/{id}/enable

POST   /api/admin/subscriptions/{id}/traffic
POST   /api/admin/subscriptions/{id}/extend

GET    /api/admin/orders
GET    /api/admin/payments
```

---

# 44. Bot Webhook API

```text
POST /api/telegram/webhook
```

必须验证：

```text
X-Telegram-Bot-Api-Secret-Token Header
```

该 Token 必须是高熵随机值，使用常量时间比较，支持轮换，且不得出现在 URL、访问日志和异常信息中。

还必须：

```text
限制 Content-Type 和请求体大小
按 update_id 建立 UNIQUE 去重记录
对 Update 处理异步化
限制总体请求速率和并发
仅订阅业务所需 allowed_updates
```

Telegram 对非 2xx 响应会重试，因此 Update handler 必须幂等。仅验证请求头不能替代 callback 中的 User、Plan 和 Subscription Ownership 校验。

---

# 45. Payment API

```text
POST /api/payment/epay/notify
GET  /payment/epay/return
```

`return`：

只负责向用户显示：

```text
支付处理中 / 支付完成
```

最终支付状态来自：

```text
notify
```

---

# 46. 内部 Node API

Phase 1 如果完全 SSH：

不需要公开 Node API。

Panel：

```text
SSH → node
```

Phase 2 改为 Agent：

```text
POST /internal/node/heartbeat
POST /internal/node/traffic
POST /internal/node/quota/request
```

认证：

```text
mTLS
```

或者：

```text
HMAC
```

不能使用简单固定 token。

HMAC 必须覆盖 HTTP method、规范化 path、body hash、node id、timestamp 和 nonce；服务端限制时间窗口并持久化 nonce 防重放。每个节点使用独立密钥并支持轮换。mTLS 必须校验证书链、节点身份、用途和吊销状态。

---

# 47. Queue Jobs

至少实现：

```text
DeployProxyNodeJob

CreateProxySecretJob
DisableProxySecretJob
EnableProxySecretJob
RemoveProxySecretJob

ProvisionSubscriptionJob
DisableSubscriptionJob
EnableSubscriptionJob

SyncNodeTrafficJob
SyncSubscriptionTrafficJob

NodeHealthCheckJob

SendTelegramMessageJob
SendExpiryNotificationJob
SendTrafficNotificationJob

ProcessPaidOrderJob
```

---

# 48. Events

推荐：

```text
OrderCreated
OrderPaid

SubscriptionCreated
SubscriptionActivated

SubscriptionTrafficChanged
SubscriptionTrafficThresholdReached
SubscriptionTrafficExhausted

SubscriptionExpiring
SubscriptionExpired

NodeOnline
NodeOffline

ProxySecretCreated
ProxySecretDisabled
```

业务模块不要互相硬调用。

---

# 49. Scheduler

推荐：

```text
每 10 秒:
Traffic Sync
```

Laravel Scheduler 默认分钟粒度的话，可：

```text
常驻 Worker
```

或者 Node Traffic Daemon。

其他：

```text
每分钟:
Expire Subscription
Node Health

每5分钟:
Retry Failed Secret Sync

每5分钟:
Payment Reconciliation
Paid-but-Unprovisioned Reconciliation

每天:
Cleanup Logs

每天凌晨:
Database Backup
```

---

# 50. 分布式锁

必须防止：

```text
两个 Worker
同时禁用 Subscription
```

使用带租约和所有者校验的：

```text
Redis Lock
```

例如：

```text
lock:
subscription:{id}:disable
```

同样用于：

```text
provision
enable
traffic sync
payment
```

锁必须使用随机 owner token、有限 TTL、必要时续租，并只允许锁所有者释放。禁止裸 `SETNX` 后无过期时间，也禁止无条件 `DEL` 锁。

分布式锁只用于减少并发工作，不能代替数据库唯一约束、条件状态转换、事务 Outbox 和任务幂等。所有节点状态命令必须携带单调递增的 `desired_state_version`，节点拒绝旧版本命令。

---

# 51. 状态一致性

中央数据库是：

```text
Source of Truth
```

节点不是。

例如数据库：

```text
Subscription = expired
```

但是节点 Secret：

```text
active
```

则 Reconcile Job 必须修复：

```text
disable
```

定时：

```text
ReconcileProxyStateJob
```

每：

```text
5分钟
```

检查。

---

# 52. Retry 策略

节点操作失败：

```text
Retry:
5s
15s
30s
60s
300s
```

最大次数后：

```text
status = error
```

后台报警。

---

# 53. 审计日志

所有重要管理动作记录：

```text
admin
action
object
before
after
ip
user_agent
created_at
```

例如：

```text
admin 1
ADD_TRAFFIC
subscription 100
+100GB
```

---

# 54. Secret 安全

Proxy Secret：

```text
禁止明文打印日志
```

数据库：

建议：

```text
Laravel encrypted cast
```

或者专用 Encryption Service。

Admin API：

默认不返回完整 Secret。

只有：

```text
用户本人 Bot
```

需要获取时解密。

---

# 55. Telegram Bot 安全

必须防：

```text
伪造 callback_data
修改 plan id
购买隐藏套餐
越权查 subscription
```

每次 Callback：

必须重新查：

```text
Telegram User
Plan
Subscription Ownership
```

不能相信 callback_data。

---

# 56. EPay 安全

必须：

```text
HTTPS
签名验证
金额验证
订单号验证
状态验证
Merchant ID 验证
幂等
```

不能接受：

```text
前端上传：
order=123
status=success
```

然后直接开通。

---

# 57. 管理后台安全

至少：

```text
2FA
Rate Limit
CSRF
Secure Cookie
Session Rotation
Login Audit
IP Logging
Password Hash
Per-route Authorization
SameSite Cookie
Content Security Policy
Strict CORS Allowlist
```

生产环境强制 2FA，并提供受审计的恢复流程。登录、2FA、密码重置、SSH 测试、部署、支付配置和 Secret 操作必须分别限流。

使用 Session Cookie 时必须启用 `HttpOnly`、`Secure`、适当的 `SameSite`，并在登录、提权和密码修改后轮换 Session。所有改变状态的请求必须执行 CSRF 校验。

所有列表、详情和操作接口均需独立授权，不能依赖前端隐藏按钮。高风险操作需重新认证或二次确认，并写入不可篡改审计日志。

服务器返回的部署日志、节点错误、支付字段、Telegram 用户资料和审计 before/after 都属于不可信内容；前端必须按文本编码输出并设置 CSP，禁止使用未净化的 `v-html`，防止存储型 XSS。

建议：

```text
Argon2id
```

SSH 私钥页面：

只能：

```text
设置/替换
```

不能：

```text
再次显示完整 Private Key
```

---

# 58. 日志脱敏

禁止日志包含：

```text
SSH private key
SSH password
EPAY key
BOT token
完整 proxy secret
用户 password
```

Proxy Secret、SSH Key、支付密钥和 Bot Token 不得以任何截断形式写入日志。需要关联排障时只记录数据库对象 ID、密钥版本或专用不可逆指纹，例如：

```text
proxy_secret_id=123
key_version=4
secret_fingerprint=HMAC(log_fingerprint_key, secret)
```

日志指纹必须使用独立用途密钥，禁止直接记录普通 SHA-256(secret)，以免低熵 Secret 被离线枚举。

---

# 59. MVP 阶段

第一阶段必须完成：

```text
Admin Login

Server CRUD
SSH Test
MTProxyMax Deploy

Node CRUD
Node Health

Plan CRUD

Telegram /start
Telegram Plan List

Order Create
EPay Payment
EPay Notify

Subscription Create

Proxy Secret Create
Proxy Secret Disable
Proxy Secret Enable

Multi-node Traffic Aggregation

Traffic Exhaust Disable

Expiry Disable

Telegram Traffic Query

Telegram Node List

Traffic Notification
Expiry Notification

Admin User/Subscription View
```

---

# 60. Phase 1 暂时不做

为了避免项目失控，第一版不建议做：

```text
邀请码
分销
余额
钱包
退款自动化

多币种
多商户

复杂优惠券

APP
Web 用户中心

节点自动选择
智能负载均衡

Agent
严格 Token Quota

多租户

自定义角色和复杂 RBAC（MVP 基础角色授权仍为必做）
```

这些 Phase 2 再做。

---

# 61. Phase 2

可以增加：

```text
优惠券

邀请返利
代理商

余额账户

多支付渠道

Cloudflare API

自动创建 DNS

节点 Agent

额度 Token

节点自动扩容

异常流量检测

设备/IP限制

速度限制

TG 登录 Web 用户中心

节点测速

节点负载自动分配

Prometheus
Grafana

Sentry

多语言

多币种
```

---

# 62. 推荐代码目录

```text
app/

Domain/
    User/
    Plan/
    Order/
    Payment/
    Subscription/
    Proxy/
    Traffic/
    Telegram/

Services/
    Proxy/
        ProxyEngine.php
        MTProxyMaxEngine.php

    Payment/
        PaymentGateway.php
        EpayGateway.php

    SSH/
        SSHClient.php

    Traffic/
        TrafficService.php
        QuotaService.php

    Telegram/
        TelegramBotService.php

Jobs/
    Proxy/
    Subscription/
    Traffic/
    Payment/
    Notification/

Events/

Listeners/

Models/

Http/
    Controllers/
        Admin/
        Telegram/
        Payment/

Console/
    Commands/
```

---

# 63. MTProxyMax Adapter

建议实现：

```text
MTProxyMaxEngine
```

方法：

```text
installNode()

getVersion()

getStatus()

createSecret(
    label,
    quota,
    expiry,
    connectionLimit
)

disableSecret()

enableSecret()

removeSecret()

getTraffic()

resetTraffic()

getSecrets()
```

所有 SSH 命令只能存在于：

```text
MTProxyMaxEngine
```

上层业务：

禁止知道具体 CLI。

---

# 64. Proxy Secret 创建策略

推荐：

```text
不同节点使用不同 Secret
```

例如：

```text
Tokyo:
secret_A

Singapore:
secret_B

US:
secret_C
```

而不是：

```text
三个节点完全相同 Secret
```

理由：

```text
单节点撤销
流量统计
问题排查
迁移
安全
```

更容易。

---

# 65. 节点限制与中央限制关系

节点侧必须配置本地安全上限，但所有节点尚未消费的本地分配额度之和不得超过中央剩余额度。

例如：

```text
中央：
100GB

Tokyo 可用分配：
34GB

Singapore 可用分配：
33GB

US 可用分配：
33GB
```

中央负责：

```text
跨服务器全局计费
```

节点负责：

```text
防 Panel 临时失联导致无限流量
```

禁止给每个节点都配置 100GB 或 105GB；否则三个节点失联时可能分别消费完整额度，实际总消费会放大到 300GB 或更多。

MVP 如果不实现短期额度租约，只能采用保守静态分配并在 Panel 在线时重新平衡，同时明确可用性限制。需要同时获得高利用率和严格全局额度时，必须提前实现 Phase 2 的短期 Traffic Token / Lease：

```text
available_to_allocate =
total_limit
- durable_used
- outstanding_unexpired_leases
```

Lease 必须绑定 Subscription、Node、Secret、epoch 和过期时间；重复申请使用幂等键。节点只能消费已获批且未过期的额度。

---

# 66. Panel 故障保护

如果 Panel 与节点失去联系：

不能导致：

```text
用户获得无限流量
```

因此节点应保留：

```text
expiry
local allocated quota / unexpired lease
```

作为 safety limit。

额度租约过期且无法联系 Panel 时必须 fail closed，停止继续消耗新额度。若产品选择 fail open，必须明确记录为商业风险，不能宣称具有可靠的全局额度保护。

---

# 67. Node 故障恢复

服务器恢复后：

```text
Node Online
   ↓
Reconcile
```

查询：

```text
当前该 Node 应存在的 active subscriptions
```

然后：

```text
缺 Secret
→ Create

应该 Disabled
但是节点 Active
→ Disable

数据库 Active
节点 Disabled
→ Enable
```

---

# 68. 后台人工赠送流量

例如：

```text
+20GB
```

不要直接修改套餐原始值。

推荐记录：

```text
subscription_adjustments
```

表：

```text
id
subscription_id
type
value
reason
admin_id
created_at
```

类型：

```text
traffic_bonus
traffic_deduct
extend_days
```

然后：

```text
bonus_traffic_bytes += value
```

保留审计。

---

# 69. 时间处理

数据库全部：

```text
UTC
```

前端根据：

```text
System Timezone
```

显示。

不要：

```text
服务器1 Tokyo Time
服务器2 Singapore Time
数据库 UTC
```

混用。

---

# 70. 流量单位

数据库统一：

```text
Bytes
```

禁止：

```text
traffic = 100
```

但不知道：

```text
MB / GB
```

所有套餐输入：

```text
GB
```

保存：

```text
bytes
```

---

# 71. 100GB 定义

后台必须明确选择：

二进制：

```text
1 GB =
1024^3 bytes
```

推荐使用这种模式。

全系统统一。

---

# 72. 支付金额

金额不能使用 Float。全系统统一使用最小货币单位整数：

```text
amount_cents BIGINT
```

例如：

```text
¥15.00

1500
```

`plans`、`orders`、`payments`、退款和对账均使用同一表示方式，并使用 ISO 4217 币种代码。禁止同一系统中混用 DECIMAL 元和整数分。

避免：

```text
14.999999
```

问题。

---

# 73. 订单过期

未支付订单：

```text
30分钟
```

自动：

```text
expired
```

但 EPay callback 边界情况：

如果支付渠道确认确实支付成功：

必须配置明确且唯一的 late-payment 策略，不能由 callback 临时决定：

```text
manual_review（MVP 默认）
或
auto_fulfill_original_snapshot
```

无论哪种策略，都必须先记录真实支付和唯一 provider_trade_no，禁止把已收款回调当作无效请求丢弃。自动履约时只能按原订单快照开通，不能读取当前套餐；人工处理必须产生审计记录。

---

# 74. Payment Reconciliation

每天执行：

```text
PaymentReconcileJob
```

用于发现：

```text
EPay paid
Panel pending
```

情况。

如果 EPay 支持主动查单 API：

自动修正。

---

# 75. Telegram 通知失败

Bot：

```text
blocked by user
chat not found
```

不要导致业务事务失败。

通知：

```text
Queue async
```

失败记录即可。

---

# 76. Telegram Rate Limit

批量通知时：

必须 Queue。

避免 Telegram：

```text
429 Too Many Requests
```

遇到 Retry-After：

按 Telegram 返回时间重试。

---

# 77. 删除用户策略

禁止硬删除存在历史订单的用户。

使用：

```text
soft delete
```

或者：

```text
status = disabled
```

订单和支付记录永久保存。

---

# 78. 删除服务器策略

禁止：

```text
直接 DELETE
```

应该：

```text
Disable
→ Migrate subscriptions
→ Verify
→ Delete
```

---

# 79. 节点维护模式

后台：

```text
Maintenance
```

意味着：

```text
现有用户可以继续使用
新订阅不再创建 Secret
```

另一个：

```text
Disabled
```

意味着：

```text
停止服务
```

两者必须区分。

---

# 80. 验收测试：支付

场景：

```text
用户购买 ¥15 套餐
```

验证：

```text
订单 pending

↓ EPay success

订单 paid
只开通一次

Subscription active
节点 Secrets 创建

Bot 收到开通通知
```

重复回调 10 次：

```text
只能有 1 个 Subscription
```

---

# 81. 验收测试：共享流量

套餐：

```text
100GB
```

三个节点：

```text
Tokyo 20GB
SG 30GB
US 40GB
```

后台必须：

```text
Used = 90GB
Remaining = 10GB
```

Tokyo 再：

```text
10GB
```

达到：

```text
100GB
```

必须：

```text
Subscription =
traffic_exhausted
```

三节点：

```text
全部 disabled
```

---

# 82. 验收测试：到期

Subscription：

```text
expires_at =
当前时间 - 1分钟
```

Scheduler 执行后：

```text
status =
expired
```

全部节点 Secret：

```text
disabled
```

Bot：

```text
显示套餐已到期
```

---

# 83. 验收测试：节点故障

让：

```text
Singapore Node
```

离线。

预期：

```text
Node offline

User Subscription
仍然 active

Tokyo/US
继续可用
```

节点恢复：

```text
自动 Reconcile
```

---

# 84. 验收测试：服务器 SSH

错误：

```text
Private Key
```

必须：

```text
Test Connection Failed
```

部署任务：

```text
不启动
```

正确：

```text
SSH OK
```

部署：

```text
成功安装 MTProxyMax
状态 Online
```

---

# 85. 验收测试：权限

User A：

```text
subscription 100
```

不能通过修改 Telegram callback：

```text
subscription_id=101
```

查看 User B 数据。

所有查询：

```text
WHERE user_id =
current_user.id
```

---

# 85.1 验收测试：安全与故障一致性

必须自动化验证：

```text
支付事务 COMMIT 后、队列投递前进程崩溃
→ Outbox Relay 最终开通且只开通一次

相同 Telegram update_id 重放 10 次
→ 只执行一次业务动作

相同 traffic observation 重放 10 次
→ 只计费一次

Redis 清空并重启
→ 从 MySQL 重建缓存，已用流量不丢失也不回退

Worker 持锁后崩溃
→ TTL 后可恢复，旧 Worker 不能释放新 Worker 的锁

Disable version=10 后到达迟到的 Enable version=9
→ 节点拒绝旧命令

Panel 与全部节点断网
→ 所有节点可继续消耗的未过期分配总和不超过中央剩余额度

首次 SSH fingerprint 未带外确认
→ 拒绝部署

Fake TLS Domain / username 包含 Shell 元字符
→ 输入被拒绝且不执行任何命令

数据库导出
→ 不包含明文 Proxy URL、Proxy Secret、SSH Key、Bot Token 或支付密钥

越权 Admin 调用部署、付款配置或 Secret 重建接口
→ 403，并产生安全审计
```

---

# 86. 性能目标

第一阶段：

```text
10,000 用户
100+ Proxy Nodes
100+ 万 Traffic Log / 日
```

能够正常运行。

Traffic Log 数据量较大后：

```text
按月分区
```

或：

```text
定期聚合
```

---

# 87. 流量历史聚合

原始：

```text
traffic_usage_logs
```

只保存：

```text
30~90天
```

生成：

```text
traffic_daily
```

```text
subscription_id
node_id
date
bytes
```

长期保存。

---

# 88. 备份

必须备份：

```text
MySQL
系统配置
```

SSH Key 虽在 DB 加密：

仍需备份。

建议：

```text
每天数据库备份
7 daily
4 weekly
6 monthly
```

异地保存。

备份要求：

```text
备份文件客户端加密
独立备份密钥
最小访问权限
下载与恢复审计
完整性校验
定期恢复演练
明确 RPO / RTO
```

禁止把生产 `.env`、KMS 凭据、SSH 主密钥或备份解密密钥与数据库备份存放在同一位置。Secret Manager 中的配置应使用其原生版本化和灾难恢复方案单独保护。

---

# 89. 监控

建议：

```text
Prometheus
Grafana
```

指标：

```text
Node Up
CPU
RAM
Disk

Proxy Connections
Proxy Traffic

User Traffic
Active Subscriptions

Queue Length
Queue Failed

Payment Success Rate

Telegram Send Failure
```

---

# 90. 告警

管理员 Telegram Bot：

```text
🔴 Node Offline

Tokyo-01
IP: xxx

Offline Since:
12:30

Last Error:
SSH timeout
```

以及：

```text
Queue Failed
Disk > 90%
Payment Callback Error
DB Error
```

---

# 91. 第一阶段开发顺序

推荐严格按照：

## Sprint 1

```text
Laravel Project
DB
Redis
Queue
Admin Login
```

## Sprint 2

```text
Server CRUD
SSH Client
SSH Test
Deployment
MTProxyMax Adapter
```

## Sprint 3

```text
Node
Node Group
Plan
```

## Sprint 4

```text
Telegram User
Bot
Plan List
Order
```

## Sprint 5

```text
EPay
Payment Callback
Subscription
```

## Sprint 6

```text
Secret Provision
Node Links
```

## Sprint 7

```text
Traffic Sync
Shared Quota
Quota Exhaust
```

## Sprint 8

```text
Expiry
Notifications
```

## Sprint 9

```text
Reconcile
Retry
Audit
Security
```

## Sprint 10

```text
Tests
Monitoring
Production Deployment
```

---

# 92. Coding Agent 第一条总任务

可直接给 Coding Agent：

```text
Build a production-oriented Telegram MTProto Proxy commercial management panel.

Architecture:

Laravel backend
Vue 3 admin
MySQL
Redis
Laravel Queue/Horizon
Telegram Bot webhook
EPay payment adapter
MTProxyMax/telemt nodes controlled by SSH

The central Subscription entity must be the authoritative source of quota and expiry.

One subscription may have secrets on multiple proxy servers, but all node traffic must aggregate into one global subscription traffic quota.

When the subscription quota is exhausted or expires, every node secret belonging to the subscription must be disabled.

All node operations must go through a ProxyEngine abstraction. Implement MTProxyMaxEngine as the first adapter.

All payment integrations must go through PaymentGateway abstraction. Implement EpayGateway first.

Do not place shell commands inside controllers.

Do not execute SSH operations inside HTTP request handlers. Use queued jobs.

All payment callbacks must be idempotent.

All subscription provisioning and disabling operations must be idempotent and protected by distributed locks.

SSH private keys, EPay secrets, Telegram Bot tokens and proxy secrets must never be logged and must be encrypted at rest.

Use database migrations, service classes, jobs, events, listeners, policies, tests and typed DTOs where appropriate.

Implement the system module by module and provide automated tests for payment idempotency, subscription provisioning, multi-node traffic aggregation, quota exhaustion and expiry handling.
```

---

# 93. MVP Definition of Done

项目只有满足以下条件才能认为 MVP 完成：

```text
✓ Web 后台可以添加服务器

✓ SSH Key 可以安全保存

✓ 可以测试 SSH

✓ 可以一键部署 MTProxyMax

✓ 可以管理多台节点

✓ 可以创建套餐

✓ Telegram 用户可以看到套餐

✓ 用户可以生成订单

✓ 可以跳转 EPay

✓ EPay 支付成功自动开通

✓ 每个节点自动建立 Secret

✓ Telegram 可以获取代理链接

✓ 多节点共用套餐总流量

✓ 流量用尽自动禁用所有节点

✓ 到期自动禁用所有节点

✓ 购买/续费后可以恢复

✓ Telegram 可以查询流量

✓ Telegram 可以查询到期时间

✓ Telegram 可以查询节点

✓ Telegram 可以收到流量提醒

✓ Telegram 可以收到到期提醒

✓ 管理员可以人工禁用/启用

✓ 节点掉线不会导致整个套餐失效

✓ 节点恢复后可以自动同步状态

✓ 重复支付 Callback 不会重复开套餐

✓ 支付提交后即使进程崩溃，Outbox 仍会最终开通

✓ 套餐修改不会改变已创建订单的价格和权益

✓ 重复流量观测不会重复计费

✓ Redis 数据丢失不会导致总账丢失或回退

✓ 多节点本地未消费额度总和不超过中央剩余额度

✓ 数据库不保存包含完整 Secret 的代理 URL

✓ Telegram Webhook 使用 Secret Header 且按 update_id 去重

✓ SSH 首次 fingerprint 已通过带外渠道确认

✓ 部署输入无法注入 Shell，下载产物固定版本且校验完整性

✓ SSH Key / Secret / Payment Key 不出现在日志

✓ 关键管理员操作有 Audit Log

✓ 每个 Admin API 均执行服务端角色授权

✓ 备份加密、密钥分离且通过恢复演练
```

---

# 94. 最终产品结构

最终应该形成：

```text
TGProxy Panel
│
├── Admin
│   ├── Users
│   ├── Plans
│   ├── Orders
│   ├── Payments
│   ├── Subscriptions
│   ├── Servers
│   ├── Nodes
│   ├── Traffic
│   └── Settings
│
├── Billing
│   ├── Plan
│   ├── Order
│   ├── Payment
│   └── Subscription
│
├── Proxy Controller
│   ├── SSH
│   ├── MTProxyMax Adapter
│   ├── Deployment
│   ├── Secret Management
│   └── Health
│
├── Traffic
│   ├── Collector
│   ├── Aggregator
│   ├── Shared Quota
│   └── Enforcement
│
├── Telegram
│   ├── User Registration
│   ├── Shop
│   ├── Orders
│   ├── Nodes
│   ├── Usage
│   └── Notification
│
└── Payment
    └── EPay
```

核心原则只有五条：

```text
1. Panel 是唯一业务控制中心。

2. Subscription 是唯一流量和有效期总账。

3. Node 只负责实际提供 MTProto Proxy。

4. 所有节点共享 Subscription 总流量，而不是每台节点独立发套餐额度。

5. 所有支付、流量、节点操作都必须做到幂等、可重试、可审计。
```
