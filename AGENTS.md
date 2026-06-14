# 后端（server）— AI 编码代理指南

SaiAdmin 6.x 后端，基于 **Webman / Workerman** 高性能常驻内存 PHP 框架（PHP ≥ 8.1），默认端口 **8787**。

## 代码放在哪里

| 目录 | 职责 | 改动建议 |
|------|------|----------|
| `plugin/saiadmin/` | 核心框架（用户/角色/菜单/权限/字典/代码生成等） | 升级会覆盖，**非必要勿改** |
| `plugin/saipackage/` | 插件市场 | 同上 |
| `app/` | **项目自有业务** | 新功能优先放这里 |
| `config/` | 应用配置（路由、数据库、中间件等） | 按需改 |
| `vendor/` | Composer 依赖 | **禁止手改** |

## 运行 / 调试

- 启动：`php start.php start`（前台）/ `php start.php start -d`（守护）；Windows 用 [windows.bat](windows.bat)
- **改了 PHP 代码必须 reload 才生效**：`php start.php reload`（常驻内存特性，易踩坑）
- 日志在 `runtime/logs/`（`webman.log`、`workerman.log`）。配置：[config/server.php](config/server.php)、数据库 [config/database.php](config/database.php)（读 `.env`）

## 分层架构（CRUD 四层）

按业务模块分目录，范例「系统用户」：

| 层 | 文件 | 基类 |
|----|------|------|
| Controller | [plugin/saiadmin/app/controller/system/SystemUserController.php](plugin/saiadmin/app/controller/system/SystemUserController.php) | `BaseController` |
| Logic | [plugin/saiadmin/app/logic/system/SystemUserLogic.php](plugin/saiadmin/app/logic/system/SystemUserLogic.php) | `BaseLogic` |
| Model | [plugin/saiadmin/app/model/system/SystemUser.php](plugin/saiadmin/app/model/system/SystemUser.php) | `BaseModel` |
| Validate | [plugin/saiadmin/app/validate/system/SystemUserValidate.php](plugin/saiadmin/app/validate/system/SystemUserValidate.php) | `BaseValidate` |

基类在 [plugin/saiadmin/basic/](plugin/saiadmin/basic/)。Logic 通用方法 `add`/`edit`/`read`/`destroy`/`getList`/`search`/`transaction` 已在 [basic/think/BaseLogic.php](plugin/saiadmin/basic/think/BaseLogic.php) 实现，**勿重复造轮子**。

## 统一响应（务必使用）

Controller 里统一用基类方法，**不要手写 `json()`**（见 [basic/OpenController.php](plugin/saiadmin/basic/OpenController.php)）：

```php
return $this->success($data, '操作成功'); // => { code: 200, message, data }
return $this->fail('错误信息');           // => { code: 400, message }
```

异常统一抛 [exception/ApiException.php](plugin/saiadmin/exception/ApiException.php)。

## 路由

在插件的 `config/route.php` 注册，全部挂在 `/core` 分组下。CRUD 用 `fastRoute()`（[app/functions.php](plugin/saiadmin/app/functions.php)）一次生成 RESTful 路由：

```php
fastRoute('user', \plugin\saiadmin\app\controller\system\SystemUserController::class);
// GET /core/user/index · POST /core/user/save · PUT /core/user/update
// GET /core/user/read · DELETE /core/user/destroy · POST /core/user/import|export
```

非 CRUD 接口用 `Route::get/post(...)` 显式注册。范例 [config/route.php](plugin/saiadmin/config/route.php)。

## 鉴权与权限

中间件在 [plugin/saiadmin/app/middleware/](plugin/saiadmin/app/middleware/)：`CheckLogin`（JWT 校验）、`CheckAuth`（权限）、`SystemLog`、`CrossDomain`。

权限用 PHP 8 注解标记到方法上，`CheckAuth` 通过反射校验：

```php
#[Permission('用户数据列表', 'core:user:index')]
public function index(Request $request): Response { ... }
```

取当前登录用户用 `getCurrentInfo()`（[app/functions.php](plugin/saiadmin/app/functions.php)）。

四方支付插件（`plugin/paymentchannel`）权限码统一 `pay:` 前缀：

- **`pay:merchant:channel`**（9017）：商户代收/代付通道配置抽屉（`listByMerchant` / `batchSave` / `listTransferByMerchant` / `batchSaveTransfer`）
- **`pay:channel:*`**（9020~9026）：代收通道管理；**`pay:channel:auth`** 通道维度批量代收授权
- **`pay:transferChannel:*`**（9027~9033，Phase 9.5.7）：代付通道管理；**`pay:transferChannel:auth`** 通道维度批量代付授权

代收与代付后台菜单/权限可独立分配给运营角色。菜单种子见 [plugin/paymentchannel/db/menu.sql](plugin/paymentchannel/db/menu.sql)（插入代付菜单后路由及以下业务菜单 ID 顺延 +4，须整段重导）。

## ORM 与数据库

**实际使用 ThinkORM**（基类在 `basic/think/`；`basic/eloquent/` 为备选，勿混用）。约定：

- 表名前缀 `sa_`（如 `sa_system_user`），主键 `id`
- **软删除**：`delete_time` 字段，查询默认排除已删
- 只读字段 `created_by`、`create_time`；搜索器用 ThinkORM 的 `searchXxxAttr` / scope

## 代码生成器

优先用内置生成器产出标准 CRUD，而非纯手写。引擎 [utils/code/CodeEngine.php](plugin/saiadmin/utils/code/CodeEngine.php)，Twig 模板在 [utils/code/stub/saiadmin/php/](plugin/saiadmin/utils/code/stub/saiadmin/php/)（`controller.stub`/`logic.stub`/`model.stub`/`validate.stub`）。手写新模块时请对齐这些模板的结构与命名。

## 命名约定（PSR-4）

- 命名空间：核心 `plugin\saiadmin\<layer>\<module>\<Class>`；项目业务 `app\<layer>\<module>\<Class>`
- 文件：`XxxController.php` / `XxxLogic.php` / `XxxValidate.php`、Model 用 `Xxx.php`，类名 PascalCase
- 表字段蛇形（`real_name`），方法小驼峰（`modifyPassword`）

## 注释与变更清单

- 新建文件保留 saiadmin 文件头注释；类 / 方法用 PHPDoc 标注用途、`@param`、`@return`，复杂逻辑加行内中文注释。
- 每轮改动后列出变更清单供我审查，代码经我确认再提交。通用执行边界与清单规范见根 [../AGENTS.md](../AGENTS.md)。
