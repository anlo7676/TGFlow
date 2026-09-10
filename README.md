# TGFlow

Telegram MTProto Proxy 商业管理平台，后端基于 PHP 7.4 / Laravel 8，管理端基于 Vue 3，数据服务使用 MySQL 5.7 与 Redis。

## Docker 一键部署

服务器只需安装 Docker Engine 和 Docker Compose 插件。克隆项目后执行：

```bash
chmod +x tgflow
./tgflow deploy
```

命令会自动完成以下工作：

- 生成 `.env`、数据库密码和应用加密密钥；
- 构建前后端镜像并启动 MySQL、Redis、队列与定时任务；
- 等待数据库就绪，自动执行迁移并创建管理员；
- 在终端显示后台地址、用户名和随机密码。

部署完成后打开 `http://服务器IP:8012` 即可登录。后台端口默认为 `8012`，首次管理员用户名默认为 `admin`，密码由部署命令随机生成并显示。数据保存在 Docker Volume 中，重新构建容器不会丢失。

> 首次便捷部署默认不强制两步验证。公网生产环境应配置 HTTPS、限制后台访问来源。需要强制两步验证时，先用 `docker compose exec app php artisan admin:create 用户名 邮箱` 创建并验证一个 2FA 管理员，再将 `ADMIN_TOTP_REQUIRED` 设为 `true`。

## 设置 `.env` 配置

无需手动编辑文件，可通过统一命令设置配置：

```bash
# 修改后台端口
./tgflow env set PANEL_PORT 8012

# 设置对外访问地址
./tgflow env set APP_URL https://panel.example.com
./tgflow env set PANEL_ALLOWED_ORIGINS https://panel.example.com

# 设置 Telegram 与支付参数
./tgflow env set TELEGRAM_BOT_TOKEN your-token
./tgflow env set TELEGRAM_WEBHOOK_SECRET your-secret
./tgflow env set EPAY_BASE_URL https://pay.example.com
./tgflow env set EPAY_PID your-pid
./tgflow env set EPAY_KEY your-key

# 查看配置；密码和密钥会自动隐藏
./tgflow env list

# 应用修改
./tgflow deploy
```

常用运维命令：

```bash
./tgflow status
./tgflow logs
./tgflow restart
./tgflow stop
```

完整配置模板见 [`.env.example`](.env.example)。支付、Telegram 和代理引擎参数均为可选项，不影响首次打开后台登录。

## 本地开发

本地开发环境需要 PHP 7.4、Composer 2、Node.js 20+、MySQL 5.7.44 和 Redis 7。

1. 在 `backend` 目录复制 `.env.example` 为 `.env`，填写本地数据库与 Redis 配置。
2. 执行 `composer install`、`php artisan key:generate` 和 `php artisan migrate`。
3. 使用 `php artisan admin:create admin admin@example.com --role=super_admin` 创建管理员。
4. 启动 Laravel、队列和调度器，并在 `frontend` 目录执行 `npm install && npm run dev`。

## 验证

后端测试：

```bash
php backend/vendor/bin/phpunit --configuration backend/phpunit.xml
```

前端构建：

```bash
cd frontend
npm ci
npm run build
```

连接专用 MySQL 5.7.44 测试库后，可使用 `backend/phpunit.mysql57.xml` 验证 MySQL 专属约束。测试会清空该数据库，禁止指向开发或生产数据库。

## 安全提示

- `.env` 已被 Git 忽略，请勿提交其中的密码与密钥。
- 对外部署建议通过 HTTPS 反向代理访问，并将 `SESSION_SECURE_COOKIE` 设为 `true`。
- `SSH_MASTER_KEY`、支付密钥和 Telegram Token 应定期轮换并安全备份。
- PHP 7.4 与 Laravel 8 已停止官方安全维护，建议尽快规划升级到受支持版本。
