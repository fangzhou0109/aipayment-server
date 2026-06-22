<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：路由定义
// +----------------------------------------------------------------------

use Webman\Route;
use plugin\saiadmin\app\middleware\CheckLogin;
use plugin\saiadmin\app\middleware\CheckAuth;
use plugin\paymentchannel\app\middleware\SignVerify;
use plugin\paymentchannel\app\middleware\IpWhitelist;
use plugin\paymentchannel\app\middleware\RateLimit;
use plugin\paymentchannel\app\middleware\MerchantAuth;

/*
 * 路由分组规划（详见根 README「3. 总体架构设计」）：
 *   /pay/*       商户支付网关  —— 无后台登录态，后续接 SignVerify + IpWhitelist 中间件
 *   /core/pay/*  平台后台管理  —— 复用 saiadmin CheckLogin + CheckAuth + #[Permission]
 *   /mapi/*      商户门户接口  —— 自定义 MerchantAuth（独立商户 JWT）
 *
 * 重要：webman 按「控制器所属插件」应用中间件，saiadmin 的鉴权中间件不会自动覆盖
 * 本插件控制器，故 /core/pay 组需「显式」挂载 saiadmin 的 CheckLogin + CheckAuth，
 * 以路由组中间件方式实现路径级隔离（不污染 /pay 网关组）。
 */
Route::group('/pay', function () {
    // 健康检查：GET /pay/health —— 无需登录，返回插件名、版本与服务器时间
    Route::get('/health', [plugin\paymentchannel\app\controller\HealthController::class, 'index']);

    // 上游异步回调：POST|GET /pay/notify/{channel} —— 不走商户验签中间件，
    // 由对应通道适配器用「上游密钥」自行 verifyNotify；{channel}=通道编码。
    Route::any('/notify/{channel}', [\plugin\paymentchannel\app\controller\gateway\NotifyController::class, 'notify']);

    // 上游代付（提现下发）异步回调：POST|GET /pay/transferNotify/{channel} —— 同样不走商户验签，
    // 由代付适配器用「上游密钥」verifyNotify；成功扣减冻结 / 失败解冻退款，由 WithdrawLogic 状态机处理。
    Route::any('/transferNotify/{channel}', [\plugin\paymentchannel\app\controller\gateway\TransferNotifyController::class, 'notify']);

    // 后台测试闭环：模拟商户 notify_url，验签后回应 SUCCESS（见 config test_notify_url）
    Route::any('/test/notify', [\plugin\paymentchannel\app\controller\gateway\TestNotifyController::class, 'notify']);
});

/*
 * 商户支付网关路由组 /pay/*（带签名 + IP 白名单中间件）
 * 中间件顺序：先 SignVerify（验签 + 加载商户上下文），再 IpWhitelist（按商户配置校验来源 IP）。
 * 健康检查单独在上面无中间件分组注册，避免被验签拦截。
 */
Route::group('/pay', function () {
    // 商户下单：POST /pay/submitOrder —— 验签通过后建单并调上游，返回支付链接
    Route::post('/submitOrder', [\plugin\paymentchannel\app\controller\gateway\PayGatewayController::class, 'submitOrder']);
    // 商户查单：POST /pay/query —— 验签后按商户订单号查询（仅能查本商户订单）
    Route::post('/query', [\plugin\paymentchannel\app\controller\gateway\PayGatewayController::class, 'query']);
    // 商户代付下单：POST /pay/transfer —— 验签后建代付单（out_biz_no 幂等），按阈值自动放款或转人工
    Route::post('/transfer', [\plugin\paymentchannel\app\controller\gateway\TransferGatewayController::class, 'transfer']);
    // 商户代付查单：POST /pay/transferQuery —— 验签后按商户代付单号查询（仅能查本商户代付单）
    Route::post('/transferQuery', [\plugin\paymentchannel\app\controller\gateway\TransferGatewayController::class, 'transferQuery']);
})->middleware([
    SignVerify::class,
    IpWhitelist::class,
    // 限流（OWASP 防超频/暴力）：按 mch_id+path 固定窗口限流；Redis 异常时失败放行，不阻断支付。
    RateLimit::class,
]);

/*
 * 平台后台管理路由组 /core/pay/*
 * 组内统一挂载 saiadmin 登录 + 权限中间件；各控制器方法用 #[Permission] 声明权限码。
 */
Route::group('/core/pay', function () {
    // 平台工作台统计（全平台经营概览，只读）
    Route::get('/dashboard/stats', [\plugin\paymentchannel\app\controller\admin\DashboardController::class, 'stats']);

    // 商户管理 CRUD（fastRoute 生成 index/save/update/read/destroy）
    fastRoute('merchant', \plugin\paymentchannel\app\controller\admin\MerchantController::class);
    // 商户重置密钥（非 CRUD，显式注册）
    Route::post('/merchant/resetKey', [\plugin\paymentchannel\app\controller\admin\MerchantController::class, 'resetKey']);
    Route::post('/merchant/adjustBalance', [\plugin\paymentchannel\app\controller\admin\MerchantController::class, 'adjustBalance']);
    Route::get('/merchant/credentials', [\plugin\paymentchannel\app\controller\admin\MerchantController::class, 'credentials']);

    // 上游代收通道管理 CRUD（列表 scope channel_biz IN 1,3）
    fastRoute('channel', \plugin\paymentchannel\app\controller\admin\ChannelController::class);
    // 通道适配器下拉选项（非 CRUD，显式注册）
    Route::get('/channel/adapters', [\plugin\paymentchannel\app\controller\admin\ChannelController::class, 'adapters']);

    // 代付通道管理 CRUD（Phase 9.5.2 / 菜单 9027，权限 pay:transferChannel:*）
    fastRoute('transferChannel', \plugin\paymentchannel\app\controller\admin\TransferChannelController::class);
    Route::get('/transferChannel/transferAdapters', [\plugin\paymentchannel\app\controller\admin\TransferChannelController::class, 'transferAdapters']);

    // 商户-通道授权 CRUD + 代收/代付列表与批量保存（Phase 9.5.3 代付 API）
    fastRoute('merchantChannel', \plugin\paymentchannel\app\controller\admin\MerchantChannelController::class);
    Route::get('/merchantChannel/listByMerchant', [\plugin\paymentchannel\app\controller\admin\MerchantChannelController::class, 'listByMerchant']);
    Route::get('/merchantChannel/listTransferByMerchant', [\plugin\paymentchannel\app\controller\admin\MerchantChannelController::class, 'listTransferByMerchant']);
    Route::get('/merchantChannel/listByChannel', [\plugin\paymentchannel\app\controller\admin\MerchantChannelController::class, 'listByChannel']);
    Route::post('/merchantChannel/batchSave', [\plugin\paymentchannel\app\controller\admin\MerchantChannelController::class, 'batchSave']);
    Route::post('/merchantChannel/batchSaveTransfer', [\plugin\paymentchannel\app\controller\admin\MerchantChannelController::class, 'batchSaveTransfer']);
    Route::post('/merchantChannel/batchAuthorizeByChannel', [\plugin\paymentchannel\app\controller\admin\MerchantChannelController::class, 'batchAuthorizeByChannel']);
    Route::post('/merchantChannel/batchAuthorizeTransferByChannel', [\plugin\paymentchannel\app\controller\admin\MerchantChannelController::class, 'batchAuthorizeTransferByChannel']);

    // 商户-路由授权 CRUD + 按商户列表/批量保存（Phase 9.3.1 可选路由白名单）
    fastRoute('merchantRoute', \plugin\paymentchannel\app\controller\admin\MerchantRouteController::class);
    Route::get('/merchantRoute/listByMerchant', [\plugin\paymentchannel\app\controller\admin\MerchantRouteController::class, 'listByMerchant']);
    Route::post('/merchantRoute/batchSave', [\plugin\paymentchannel\app\controller\admin\MerchantRouteController::class, 'batchSave']);

    // 综合路由 CRUD + 路由试算（调试）
    fastRoute('route', \plugin\paymentchannel\app\controller\admin\RouteController::class);
    Route::get('/route/preview', [\plugin\paymentchannel\app\controller\admin\RouteController::class, 'preview']);

    // 路由-通道关联 CRUD
    fastRoute('routeChannel', \plugin\paymentchannel\app\controller\admin\RouteChannelController::class);

    // 代收订单管理：只读 + 补单（不开放手工增删改，订单由网关/回调驱动）
    Route::get('/order/index', [\plugin\paymentchannel\app\controller\admin\OrderController::class, 'index']);
    Route::get('/order/read', [\plugin\paymentchannel\app\controller\admin\OrderController::class, 'read']);
    Route::post('/order/reissue', [\plugin\paymentchannel\app\controller\admin\OrderController::class, 'reissue']);
    Route::post('/order/testSubmit', [\plugin\paymentchannel\app\controller\admin\OrderController::class, 'testSubmit']);
    Route::get('/order/testNotifyRecent', [\plugin\paymentchannel\app\controller\admin\OrderController::class, 'testNotifyRecent']);

    // 商户提现管理：只读 + 审核（提现由商户门户发起，后台不开放手工增删改）
    // 审核通过会触发代付下发（调代付适配器），拒绝/失败则解冻退款。
    Route::get('/withdraw/index', [\plugin\paymentchannel\app\controller\admin\WithdrawController::class, 'index']);
    Route::get('/withdraw/read', [\plugin\paymentchannel\app\controller\admin\WithdrawController::class, 'read']);
    Route::get('/withdraw/transferChannels', [\plugin\paymentchannel\app\controller\admin\WithdrawController::class, 'transferChannels']);
    Route::post('/withdraw/audit', [\plugin\paymentchannel\app\controller\admin\WithdrawController::class, 'audit']);
    Route::post('/withdraw/disburse', [\plugin\paymentchannel\app\controller\admin\WithdrawController::class, 'disburse']);

    // API 代付订单管理：只读 + 审核（下游调 /pay/transfer 进单，source=2；与提现管理物理拆分）
    // 共用 sa_pay_withdraw 与 WithdrawLogic 状态机，权限码独立 pay:transferOrder:*。
    Route::get('/transferOrder/index', [\plugin\paymentchannel\app\controller\admin\TransferOrderController::class, 'index']);
    Route::get('/transferOrder/read', [\plugin\paymentchannel\app\controller\admin\TransferOrderController::class, 'read']);
    Route::get('/transferOrder/transferChannels', [\plugin\paymentchannel\app\controller\admin\TransferOrderController::class, 'transferChannels']);
    Route::post('/transferOrder/audit', [\plugin\paymentchannel\app\controller\admin\TransferOrderController::class, 'audit']);
    Route::post('/transferOrder/disburse', [\plugin\paymentchannel\app\controller\admin\TransferOrderController::class, 'disburse']);

    // 商户充值管理：只读 + 审核（充值由商户门户发起，后台不开放手工增删改）
    // 审核通过会把金额计入商户可用余额并写资金流水（事务一致）。
    Route::get('/recharge/index', [\plugin\paymentchannel\app\controller\admin\RechargeController::class, 'index']);
    Route::get('/recharge/read', [\plugin\paymentchannel\app\controller\admin\RechargeController::class, 'read']);
    Route::post('/recharge/audit', [\plugin\paymentchannel\app\controller\admin\RechargeController::class, 'audit']);

    // 商户银行卡管理 CRUD（绑卡含首现卡风控提示；卡号 Luhn 校验）
    fastRoute('bankCard', \plugin\paymentchannel\app\controller\admin\BankCardController::class);

    // 资金流水：只读 + 导出（不可变流水账，不开放增删改）
    Route::get('/capital/index', [\plugin\paymentchannel\app\controller\admin\CapitalFlowController::class, 'index']);
    Route::post('/capital/export', [\plugin\paymentchannel\app\controller\admin\CapitalFlowController::class, 'export']);

    // 商户通知日志：只读 + 人工重发
    Route::get('/notify/index', [\plugin\paymentchannel\app\controller\admin\NotifyLogController::class, 'index']);
    Route::get('/notify/read', [\plugin\paymentchannel\app\controller\admin\NotifyLogController::class, 'read']);
    Route::post('/notify/resend', [\plugin\paymentchannel\app\controller\admin\NotifyLogController::class, 'resend']);
})->middleware([
    CheckLogin::class,
    CheckAuth::class,
]);

/*
 * 商户门户路由组 /mapi/*（独立商户 JWT）
 * 组内统一挂载 MerchantAuth 中间件（只认 plat=merchant 的 token，与平台后台物理隔离）；
 * 控制器用 $noNeedLogin 声明免登录方法（如登录入口）。
 */
Route::group('/mapi', function () {
    // 商户认证：登录（免登录）/ 登出 / 当前商户资料
    Route::get('/auth/captcha', [\plugin\paymentchannel\app\controller\merchant\AuthController::class, 'captcha']);
    Route::post('/auth/login', [\plugin\paymentchannel\app\controller\merchant\AuthController::class, 'login']);
    Route::post('/auth/logout', [\plugin\paymentchannel\app\controller\merchant\AuthController::class, 'logout']);
    Route::get('/auth/info', [\plugin\paymentchannel\app\controller\merchant\AuthController::class, 'info']);
    Route::post('/auth/modifyPassword', [\plugin\paymentchannel\app\controller\merchant\AuthController::class, 'modifyPassword']);
    Route::post('/auth/uploadAvatar', [\plugin\paymentchannel\app\controller\merchant\AuthController::class, 'uploadAvatar']);
    Route::post('/auth/updateAvatar', [\plugin\paymentchannel\app\controller\merchant\AuthController::class, 'updateAvatar']);

    // 首页统计
    Route::get('/dashboard/stats', [\plugin\paymentchannel\app\controller\merchant\DashboardController::class, 'stats']);

    // 订单（仅本商户，查单 + 手动重推下游通知）
    Route::get('/order/index', [\plugin\paymentchannel\app\controller\merchant\OrderController::class, 'index']);
    Route::get('/order/read', [\plugin\paymentchannel\app\controller\merchant\OrderController::class, 'read']);
    Route::post('/order/renotify', [\plugin\paymentchannel\app\controller\merchant\OrderController::class, 'renotify']);

    // 提现（列表 + 发起申请 + 详情）
    Route::get('/withdraw/index', [\plugin\paymentchannel\app\controller\merchant\WithdrawController::class, 'index']);
    Route::post('/withdraw/apply', [\plugin\paymentchannel\app\controller\merchant\WithdrawController::class, 'apply']);
    Route::get('/withdraw/read', [\plugin\paymentchannel\app\controller\merchant\WithdrawController::class, 'read']);

    // 代付订单（下游 API 代付单 source=2，只读查询 + 手动重推下游通知）
    Route::get('/transferOrder/index', [\plugin\paymentchannel\app\controller\merchant\TransferOrderController::class, 'index']);
    Route::get('/transferOrder/read', [\plugin\paymentchannel\app\controller\merchant\TransferOrderController::class, 'read']);
    Route::post('/transferOrder/audit', [\plugin\paymentchannel\app\controller\merchant\TransferOrderController::class, 'audit']);
    Route::post('/transferOrder/renotify', [\plugin\paymentchannel\app\controller\merchant\TransferOrderController::class, 'renotify']);

    // 充值（列表 + 发起申请 + 详情）
    Route::get('/recharge/index', [\plugin\paymentchannel\app\controller\merchant\RechargeController::class, 'index']);
    Route::post('/recharge/apply', [\plugin\paymentchannel\app\controller\merchant\RechargeController::class, 'apply']);
    Route::get('/recharge/read', [\plugin\paymentchannel\app\controller\merchant\RechargeController::class, 'read']);

    // 资金流水（列表 + 导出，仅本商户）
    Route::get('/capital/index', [\plugin\paymentchannel\app\controller\merchant\CapitalFlowController::class, 'index']);
    Route::post('/capital/export', [\plugin\paymentchannel\app\controller\merchant\CapitalFlowController::class, 'export']);

    // 银行卡（列表 + 绑卡 + 解绑 + 启停）
    Route::get('/bankCard/index', [\plugin\paymentchannel\app\controller\merchant\BankCardController::class, 'index']);
    Route::post('/bankCard/save', [\plugin\paymentchannel\app\controller\merchant\BankCardController::class, 'save']);
    Route::post('/bankCard/changeStatus', [\plugin\paymentchannel\app\controller\merchant\BankCardController::class, 'changeStatus']);
    Route::delete('/bankCard/destroy', [\plugin\paymentchannel\app\controller\merchant\BankCardController::class, 'destroy']);

    // 已开启通道（代收 / 代付，只读）
    Route::get('/channel/payList', [\plugin\paymentchannel\app\controller\merchant\ChannelController::class, 'payList']);
    Route::get('/channel/transferList', [\plugin\paymentchannel\app\controller\merchant\ChannelController::class, 'transferList']);

    // API 对接说明与沙箱测试（文档 / 测试下单 / 查单 / 签名示例 / 测试回调记录）
    Route::get('/integration/docs', [\plugin\paymentchannel\app\controller\merchant\IntegrationController::class, 'docs']);
    Route::post('/integration/testSubmit', [\plugin\paymentchannel\app\controller\merchant\IntegrationController::class, 'testSubmit']);
    Route::post('/integration/testQuery', [\plugin\paymentchannel\app\controller\merchant\IntegrationController::class, 'testQuery']);
    Route::get('/integration/testNotifyRecent', [\plugin\paymentchannel\app\controller\merchant\IntegrationController::class, 'testNotifyRecent']);
    Route::post('/integration/buildSignSample', [\plugin\paymentchannel\app\controller\merchant\IntegrationController::class, 'buildSignSample']);

    // 账户/API 密钥（查看对接凭证 + 重置密钥）
    Route::get('/account/apiInfo', [\plugin\paymentchannel\app\controller\merchant\AccountController::class, 'apiInfo']);
    Route::post('/account/resetKey', [\plugin\paymentchannel\app\controller\merchant\AccountController::class, 'resetKey']);
    Route::post('/account/updateRsaPublicKey', [\plugin\paymentchannel\app\controller\merchant\AccountController::class, 'updateRsaPublicKey']);
    Route::post('/account/updateAutoDisburseThreshold', [\plugin\paymentchannel\app\controller\merchant\AccountController::class, 'updateAutoDisburseThreshold']);
})->middleware([
    MerchantAuth::class,
]);

