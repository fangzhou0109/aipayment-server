# SaiPayment 后端（Webman）部署指南

本文档仅针对 `**server` 目录**（Webman 后端源码包），面向接手部署的运维 / 开发同学。  
按章节顺序操作即可完成**首次上线**与**日常更新**，不依赖仓库外的脚本或工具。

后端基于 **Webman / Workerman**（PHP ≥ 8.1），进程默认监听 `**0.0.0.0:8787`**，对外由 **Nginx 反向代理**暴露 HTTP API。

---

## 目录

1. [源码包说明](#1-源码包说明)
2. [部署架构一览](#2-部署架构一览)
3. [服务器环境要求](#3-服务器环境要求)
4. [目录规划](#4-目录规划)
5. [首次部署（从零到跑通）](#5-首次部署从零到跑通)
6. [Nginx 反向代理配置](#6-nginx-反向代理配置)
7. [业务配置（支付插件）](#7-业务配置支付插件)
8. [版本更新与回滚](#8-版本更新与回滚)
9. [常用运维命令](#9-常用运维命令)
10. [注意事项与踩坑](#10-注意事项与踩坑)
11. [上线自检清单](#11-上线自检清单)

---

## 1. 源码包说明

### 1.1 你拿到的是什么

交付物为 `**server/` 目录**（本文档所在目录即为包根目录，下文记为 `**$APP_ROOT`**），包含：


| 路径                                        | 说明                     |
| ----------------------------------------- | ---------------------- |
| `app/`                                    | 项目业务代码                 |
| `config/`                                 | 应用与中间件配置               |
| `plugin/saiadmin/`                        | SaiAdmin 6.x 核心        |
| `plugin/paymentchannel/`                  | 四方支付业务插件               |
| `public/`                                 | 静态资源（如商户 PHP Demo 压缩包） |
| `start.php`                               | Webman 启动入口            |
| `composer.json` / `composer.lock`         | PHP 依赖锁定               |
| `plugin/paymentchannel/db/saipayment.sql` | **完整数据库**（首次安装导入）      |


### 1.2 交付时建议包含 / 排除


| 包含                                                   | 排除（接收方自行生成）                                        |
| ---------------------------------------------------- | -------------------------------------------------- |
| 全部 PHP 源码与 `composer.lock`                           | `.env`（敏感配置，单独安全下发）                                |
| `**plugin/paymentchannel/db/saipayment.sql`**（完整数据库） | `runtime/`（日志、PID、缓存）                              |
| 本 `README.md`                                        | `vendor/`（在服务器执行 `composer install` 生成，也可一并打包以省时间） |


> 数据库只需交付 `**saipayment.sql**` 即可首次建库；`plugin/paymentchannel/db/migrations/` 供**已有库升级**；其余拆分 SQL（`paymentchannel.sql`、`menu.sql` 等）为开发参考，**部署不必使用**。

### 1.3 与前端的关系

本包**仅后端 API**。平台运营后台、商户门户为独立前端项目，需单独构建为静态文件并由 Nginx 提供；前端构建时的 **API 前缀**须与本节 Nginx、`plugin/paymentchannel/config/app.php` 中的配置一致（常见为 `/prod`）。

---

## 2. 部署架构一览

```
浏览器 / 商户服务器
        │
        ▼
   Nginx（80 / 443）
   ├─ /prod/  → 反代到 127.0.0.1:8787（生产 API）
   ├─ /api/   → 反代到 127.0.0.1:8787（可选别名）
   └─ 其他 location → 前端静态站点（非本包，需另行部署）

Webman 进程（$APP_ROOT，端口 8787）
   ├─ /core/*   平台后台 API（JWT + 权限）
   ├─ /mapi/*   商户门户 API（独立商户 JWT）
   └─ /pay/*    商户支付网关（签名 + IP 白名单，无 JWT）
```

**关键原则**

- **8787 不要对公网开放**，仅本机 Nginx 反代访问。
- Nginx 的 `location` 前缀、前端 `VITE_API_URL`、`notify_domain` 三者必须一致。
- Webman 是**常驻内存**服务，改 PHP 代码后须 `reload` / `restart` 才生效。

---

## 3. 服务器环境要求

### 3.1 操作系统

- 推荐 **Linux**（CentOS / Ubuntu / Debian；宝塔面板 + Nginx + MySQL 常见组合）。
- 生产环境请用 **守护进程** 启动（`php start.php start -d`），不要长期以前台方式运行。

### 3.2 PHP


| 项目   | 要求                                                  |
| ---- | --------------------------------------------------- |
| 版本   | **PHP ≥ 8.1**（建议 8.2 / 8.3）                         |
| 运行方式 | **CLI**（非 php-fpm 网站模式）                             |
| 禁用函数 | 须允许 `exec`、`shell_exec`、`proc_open` 等（Workerman 依赖） |


**建议扩展**（宝塔：软件商店 → PHP → 安装扩展）：

```
pdo_mysql   mbstring   json   openssl   curl   fileinfo   gd   bcmath   redis（强烈推荐）
```


| 扩展        | 用途                         |
| --------- | -------------------------- |
| `bcmath`  | 金额运算（必须）                   |
| `openssl` | RSA 签名、商户密钥（必须）            |
| `redis`   | 验证码、网关限流、日限额、路由轮询等（生产强烈建议） |
| `event`   | Linux 下提升并发（可选）            |


确认 CLI 版本（**必须与命令行 `php` 一致**）：

```bash
php -v
php -m | grep -E 'pdo_mysql|bcmath|openssl|redis'
```

### 3.3 数据库

- **MySQL 5.7+** 或 **MariaDB 10.3+**（`saipayment.sql` 由 MySQL 8.0 导出，建议生产使用 **8.0+**）
- 字符集：**utf8mb4**
- 库名与 `.env` 中 `DB_NAME` 一致，默认 `**saipayment`**

### 3.4 其他

- **Composer 2.x**
- **Redis**（`CACHE_MODE=redis` 时）
- **Nginx**（反向代理）

---

## 4. 目录规划

`$APP_ROOT` 可自选，示例：

```text
/opt/saipayment/          # 或 /www/wwwroot/saipayment/server/
├── app/
├── config/
├── plugin/
├── public/
├── runtime/              # 自动创建，须可写
├── vendor/               # composer install 生成
├── .env                  # 仅服务器本地，勿入库
├── start.php
└── README.md
```


| 项         | 约定                                     |
| --------- | -------------------------------------- |
| Webman 监听 | `0.0.0.0:8787`（见 `config/process.php`） |
| API 对外前缀  | 由 Nginx 决定，下文以 `/prod` 为例              |
| 进程运行用户    | 建议与 Nginx / 文件属主一致（如 `www`）            |


---

## 5. 首次部署（从零到跑通）

以下命令均在 `**$APP_ROOT**` 下执行。

### 步骤 1：上传并解压源码

任选一种方式将 `server` 目录放到服务器，例如：

```bash
# 示例：解压到 /opt/saipayment
mkdir -p /opt/saipayment
cd /opt/saipayment
# 将收到的 saipayment-server.zip 上传后：
unzip saipayment-server.zip
# 若解压后多一层 server/ 目录，进入该目录作为 $APP_ROOT
cd server   # 按实际结构调整
export APP_ROOT=$(pwd)
```

### 步骤 2：安装 PHP 依赖

```bash
cd $APP_ROOT
composer install --no-dev --optimize-autoloader
```

若交付包已含 `vendor/` 且 PHP 版本一致，可跳过；**换机器或升 PHP 后建议重新执行**。

国内服务器可加镜像：

```bash
composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/
```

### 步骤 3：创建 `.env`

在 `$APP_ROOT/.env` 新建文件（**不要从开发机直接拷贝含真实密码的文件到公网服务器**，按环境单独填写）：

```ini
# 数据库
DB_TYPE = mysql
DB_HOST = 127.0.0.1
DB_PORT = 3306
DB_NAME = saipayment
DB_USER = saipayment
DB_PASSWORD = 请填写强密码
DB_PREFIX =
DB_CHARSET = utf8mb4

# 缓存：无 Redis 可先用 file；生产建议 redis
CACHE_MODE = redis

# Redis
REDIS_HOST = 127.0.0.1
REDIS_PORT = 6379
REDIS_PASSWORD =
REDIS_DB = 0

# 验证码：cache 或 session
CAPTCHA_MODE = cache

# 标识用，不影响 API 路由
FRONTEND_DIR = saiadmin-artd
```

**注意**：值中避免未转义的 `|` 等特殊字符；含空格的值建议不加多余引号，保持 `KEY = value` 单行格式。

### 步骤 4：创建数据库并导入

#### 4.1 完整库文件说明

`**plugin/paymentchannel/db/saipayment.sql`** 是本系统**唯一推荐的首次安装数据库包**（phpMyAdmin 导出，约 39 张表），已包含：


| 类别            | 表前缀 / 示例      | 内容                             |
| ------------- | ------------- | ------------------------------ |
| SaiAdmin 平台核心 | `sa_system_*` | 用户、角色、菜单、权限、字典、部门、配置、操作日志等     |
| 四方支付业务        | `sa_pay_*`    | 商户、通道、订单、提现、充值、路由、资金流水等        |
| 文章模块（可选）      | `sa_article*` | 文章与轮播                          |
| 工具            | `sa_tool_*`   | 代码生成器、**定时任务**（含支付通知重试、订单超时关闭） |


文件内**已带初始化数据**：平台菜单与权限、字典、定时任务、示例通道/路由，以及**测试商户**等。  
`.env` 中 `DB_NAME` 建议与库名一致，默认为 `**saipayment`**。

> **安全提示**：SQL 中可能含导出环境的测试商户密钥、RSA 私钥等。**生产上线后务必**：修改 `admin` 密码、重置测试商户密钥、修改 JWT 密钥（见步骤 7），勿直接拿测试数据对接真实资金。

#### 4.2 建库建用户

在**空库**中导入（库内不能已有同名表，否则会报 `Table already exists`）：

```sql
CREATE DATABASE saipayment DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'saipayment'@'127.0.0.1' IDENTIFIED BY '请填写强密码';
GRANT ALL PRIVILEGES ON saipayment.* TO 'saipayment'@'127.0.0.1';
FLUSH PRIVILEGES;
```

#### 4.3 导入 `saipayment.sql`

**命令行**（路径相对于 `$APP_ROOT`）：

```bash
cd $APP_ROOT
mysql -usaipayment -p saipayment < plugin/paymentchannel/db/saipayment.sql
```

**宝塔面板**：数据库 → 选中 `saipayment` → 导入 → 选择 `saipayment.sql` → 执行（大文件可调高 PHP 上传/执行时间限制）。

导入成功后检查：

```bash
mysql -usaipayment -p saipayment -e "SHOW TABLES LIKE 'sa_pay_%'; SHOW TABLES LIKE 'sa_system_%';"
mysql -usaipayment -p saipayment -e "SELECT id,username,status FROM sa_system_user LIMIT 3;"
mysql -usaipayment -p saipayment -e "SELECT id,name,status FROM sa_tool_crontab WHERE id>=9101;"
```

#### 4.4 默认账号与上线必做


| 账号                  | 用途      | 说明                                            |
| ------------------- | ------- | --------------------------------------------- |
| `admin`             | 平台运营后台  | 初始密码一般为 `**123456**`（SaiAdmin 默认），**登录后立即修改** |
| 测试商户（如 `TEST_M001`） | 联调 / 演示 | 含示例密钥，生产请停用或重置密钥后再用                           |


#### 4.5 已有库升级（非首次安装）

**不要**对已有生产库再次导入 `saipayment.sql`（会建表冲突或覆盖数据）。仅执行增量脚本：

```bash
mysql -usaipayment -p saipayment < plugin/paymentchannel/db/migrations/xxxxxxxx.sql
```

按版本说明逐条执行 `migrations/` 下**尚未执行过**的文件；执行前**先备份数据库**。

### 步骤 5：目录权限

```bash
cd $APP_ROOT
mkdir -p runtime/logs
chown -R www:www runtime public
chmod -R 755 runtime public
```

`runtime/` 存放日志与 PID，**必须可写**。

### 步骤 6：配置 Nginx

见 [§6 Nginx 反向代理配置](#6-nginx-反向代理配置)，保存后：

```bash
nginx -t && nginx -s reload
```

### 步骤 7：配置支付对外地址

编辑 `plugin/paymentchannel/config/app.php`，见 [§7](#7-业务配置支付插件)。

生产环境另请修改 `config/plugin/tinywan/jwt/app.php` 中的 JWT 密钥（`access_secret_key`、`refresh_secret_key`）。

### 步骤 8：启动 Webman

```bash
cd $APP_ROOT
php start.php start -d
php start.php status
```

探活：

```bash
curl -s -o /dev/null -w 'local 8787 -> %{http_code}\n' http://127.0.0.1:8787/
curl -s -o /dev/null -w 'nginx prod -> %{http_code}\n' https://你的API域名/prod/
```

### 步骤 9：对接前端（另项工作）

本仓库不含前端构建产物。部署方需：

1. 单独部署平台后台、商户门户静态站点；
2. 前端生产环境 API 基址设为与 Nginx 一致（如 `VITE_API_URL=/prod`）；
3. 确认浏览器访问 `https://你的域名/prod/core/...`、`/prod/mapi/...` 可通。

---

## 6. Nginx 反向代理配置

将以下 `location` 放入 **API 域名** 的 `server { }` 中（宝塔：网站 → 设置 → 配置文件）。

**必须传递真实客户端 IP**（`X-Real-IP` / `X-Forwarded-For`），否则商户 **IP 白名单**（门户登录、`/pay/`* 网关）无法正确校验。

```nginx
# 生产 API 前缀（与前端 VITE_API_URL、notify_domain 保持一致）
location /prod/ {
    proxy_pass http://127.0.0.1:8787/;
    proxy_set_header Host $http_host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header REMOTE-HOST $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";

    proxy_connect_timeout 60s;
    proxy_send_timeout 60s;
    proxy_read_timeout 60s;
}

# 备用前缀（可选，配置与 /prod/ 相同）
location /dev/ {
    proxy_pass http://127.0.0.1:8787/;
    proxy_set_header Host $http_host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header REMOTE-HOST $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";

    proxy_connect_timeout 60s;
    proxy_send_timeout 60s;
    proxy_read_timeout 60s;
}

# 别名前缀（可选；前端若使用 /api 则启用）
location /api/ {
    if ($request_method = 'OPTIONS') {
        add_header 'Access-Control-Allow-Origin' $http_origin always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, OPTIONS' always;
        add_header 'Access-Control-Allow-Headers' '*' always;
        add_header 'Access-Control-Allow-Credentials' 'true' always;
        add_header 'Access-Control-Max-Age' 86400 always;
        return 204;
    }

    proxy_pass http://127.0.0.1:8787/;
    proxy_set_header Host $http_host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header REMOTE-HOST $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";

    proxy_connect_timeout 60s;
    proxy_send_timeout 60s;
    proxy_read_timeout 60s;
}
```


| 要点                  | 说明                                                  |
| ------------------- | --------------------------------------------------- |
| `proxy_pass` 末尾 `/` | `/prod/core/user/index` → Webman `/core/user/index` |
| 只选一个主前缀             | `/prod` 与 `/api` 可并存，但前端只能对接其中一个                    |
| HTTPS               | 在 Nginx 层配置证书；Webman 仍监听本机 HTTP 8787                |


---

## 7. 业务配置（支付插件）

文件：`plugin/paymentchannel/config/app.php`

```php
// 对外 API 根 URL（无尾斜杠），路径须与 Nginx location 一致
'notify_domain' => 'https://api.你的域名.com/prod',

// 与 notify_domain 末尾路径一致
'api_path_prefix' => '/prod',
```


| 能力     | URL 示例                                      |
| ------ | ------------------------------------------- |
| 商户下单   | `{notify_domain}/pay/submitOrder`           |
| 上游代收回调 | `{notify_domain}/pay/notify/{通道编码}`         |
| 上游代付回调 | `{notify_domain}/pay/transferNotify/{通道编码}` |
| 平台后台   | `{notify_domain}/core/...`                  |
| 商户门户   | `{notify_domain}/mapi/...`                  |


修改本文件或 `config/`、`.env` 后执行：

```bash
php start.php restart -d
```

---

## 8. 版本更新与回滚

### 8.1 更新前备份

```bash
# 数据库
mysqldump -usaipayment -p saipayment > backup_$(date +%Y%m%d).sql

# 代码与配置（保留 .env）
cd $(dirname $APP_ROOT)
tar czf saipayment-server_$(date +%Y%m%d).tar.gz \
  --exclude='runtime' --exclude='vendor' server/
```

### 8.2 覆盖代码（保留 .env 与 runtime）

```bash
cd $APP_ROOT

# 1. 停止服务（可选，减少写入中途状态）
php start.php stop

# 2. 用新源码覆盖 app/ config/ plugin/ start.php composer.* 等
#    切勿覆盖 .env
# 示例：解压新版本到临时目录后 rsync
# rsync -av --exclude='.env' --exclude='runtime/' /tmp/server-new/ $APP_ROOT/

# 3. 依赖有变时
composer install --no-dev --optimize-autoloader

# 4. 执行增量 SQL（若有；勿再次导入 saipayment.sql）
mysql -usaipayment -p saipayment < plugin/paymentchannel/db/migrations/xxxxxxxx.sql

# 5. 重启
php start.php start -d
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8787/
```

### 8.3 回滚

1. 恢复更新前的代码 tar 包；
2. `composer install`（若 vendor 一并备份可跳过）；
3. 数据库用步骤 8.1 的 `mysqldump` 备份恢复；
4. `php start.php restart -d`。

> 仅在**全新空机**灾难恢复时，才可再次导入 `saipayment.sql`（会丢失业务数据，相当于重装）。

### 8.4 开机自启（可选）

Webman 官方支持 Supervisor。宝塔也可在「计划任务」中配置开机执行：

```bash
cd /opt/saipayment/server && php start.php start -d
```

更稳妥做法是使用 **systemd** 或 **Supervisor** 托管 `php start.php start -d`，确保崩溃后自动拉起（按运维规范自行编写 unit 文件）。

---

## 9. 常用运维命令

在 `$APP_ROOT` 下执行：


| 命令                         | 场景                  |
| -------------------------- | ------------------- |
| `php start.php start -d`   | 首次 / 停机后启动          |
| `php start.php stop`       | 维护前停止               |
| `php start.php restart -d` | 改配置、换 `.env`、大版本更新  |
| `php start.php reload`     | 仅改 PHP 业务代码         |
| `php start.php status`     | 查看 worker 是否 `[OK]` |


日志：

```text
runtime/logs/webman.log
runtime/logs/workerman.log
runtime/logs/stdout.log
```

---

## 10. 注意事项与踩坑

### 10.1 不要用 php-fpm 跑本后端

- 错误：宝塔「网站」→ 运行目录指到 `public/` 用 php-fpm。  
- 正确：**独立进程** `php start.php start -d` + Nginx **反代 8787**。

### 10.2 `.env` 只存在于服务器

- 更新源码时**不要覆盖**生产 `.env`。  
- 数据库账号密码、Redis 等仅保存在服务器本地。

### 10.3 IP 白名单不生效

1. Nginx 未配置 `X-Real-IP` / `X-Forwarded-For`（见 §6）。
2. 平台后台商户：白名单开关为「开启」且已填写真实出口 IP。
3. 白名单 IP 与商户实际公网 IP 不一致。
4. 代码更新后未 `reload` / `restart`。

### 10.4 接口 404

- 前端 API 前缀与 Nginx `location` 不一致（`/prod` vs `/api`）。  
- `notify_domain`、`api_path_prefix` 配错。  
- Webman 未启动或 8787 被占用。

### 10.5 Redis 相关

- `CACHE_MODE=redis` 但 Redis 未启动 → 验证码、限流异常。  
- 网关日限额依赖 Redis；异常时可能 **拒单**（fail-close）。

### 10.6 定时任务

- 首次安装已由 `saipayment.sql` 写入 `sa_tool_crontab`（如 9101 通知重试、9102 订单超时）。  
- 依赖 Webman 进程 `plugin.saiadmin.task`；`status` 中应看到对应 worker。  
- 可在平台后台「工具 → 定时任务」查看是否启用。

### 10.7 安全建议

- 防火墙**勿**对公网开放 8787。  
- 修改默认 `admin` 密码与 JWT 密钥。  
- 生产环境将 `test_notify.accept_invalid_sign` 设为 `false`。  
- 定期备份数据库；`runtime/` 可删除重建。

### 10.8 多 PHP 版本共存

宝塔若同时安装 PHP 7.x / 8.x，务必确认命令行默认 `php` 为 **8.1+**：

```bash
which php
php -v
# 如需指定：/www/server/php/83/bin/php start.php start -d
```

---

## 11. 上线自检清单

- [ ] `php -v` ≥ 8.1，扩展 `pdo_mysql`、`bcmath`、`openssl` 已装
- [ ] `composer install` 成功，`vendor/` 存在
- [ ] `.env` 数据库 / Redis 连接正确
- [ ] 已导入 `plugin/paymentchannel/db/saipayment.sql`（或 migrations 已补齐升级）
- [ ] `php start.php status` 中 `webman`、`plugin.saiadmin.task` 等为 `[OK]`
- [ ] `curl http://127.0.0.1:8787/` 返回 200
- [ ] `curl https://API域名/prod/` 返回 200
- [ ] 平台后台登录正常（`/prod/core/...`）
- [ ] 商户门户登录正常（`/prod/mapi/auth/login`）
- [ ] `notify_domain` 与上游回调地址一致，试单闭环通过
- [ ] IP 白名单：非名单 IP 无法登录商户门户
- [ ] 已改 admin 默认密码与 JWT 密钥
- [ ] `runtime/` 可写，日志无持续报错

---

## 附录 A：本包内关键路径速查


| 用途              | 路径                                            |
| --------------- | --------------------------------------------- |
| 环境变量            | `.env`                                        |
| 启动入口            | `start.php`                                   |
| 监听端口            | `config/process.php` → `8787`                 |
| 支付业务配置          | `plugin/paymentchannel/config/app.php`        |
| JWT 密钥          | `config/plugin/tinywan/jwt/app.php`           |
| **完整数据库（首次安装）** | `**plugin/paymentchannel/db/saipayment.sql`** |
| 增量迁移（已有库升级）     | `plugin/paymentchannel/db/migrations/*.sql`   |
| 开发约定（可选阅读）      | `AGENTS.md`                                   |


## 附录 B：`db/` 目录文件说明


| 文件                                  | 部署是否必需     | 说明                                  |
| ----------------------------------- | ---------- | ----------------------------------- |
| `**saipayment.sql**`                | **首次安装必需** | 全库结构 + 种子数据，一键导入                    |
| `migrations/*.sql`                  | 升级时需要      | 按版本增量执行，不可重复跑已执行过的脚本                |
| `paymentchannel.sql`                | 不必         | 仅业务表 DDL，开发拆分用                      |
| `menu.sql` / `crontab.sql`          | 不必         | 已合并进 `saipayment.sql`               |
| `plugin/saiadmin/db/saiadmin-*.sql` | 不必         | SaiAdmin 原始种子，已合并进 `saipayment.sql` |


