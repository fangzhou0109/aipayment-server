<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：插件应用配置 —— webman 据本文件是否“非空”判定插件是否启用
// +----------------------------------------------------------------------

return [
    // 插件开关：webman 加载应用插件时读取（缺省视为启用，此处显式声明意图）
    'enable' => true,
    // 控制器类名后缀，与 saiadmin 保持一致
    'controller_suffix' => 'Controller',
    // 关闭控制器复用：每次请求重建控制器实例。
    // 支付为常驻内存（Workerman）服务，复用会导致请求间状态污染，必须关闭。
    'controller_reuse' => false,
    // 插件版本号
    'version' => '1.0.0',
    // 平台对外 API 基址（须与 nginx 反代一致，**不含** /pay 后缀）。
    // 商户网关：{notify_domain}/pay/submitOrder；上游回调：{notify_domain}/pay/notify/{通道编码}。
    // 宝塔/nginx 常见为 https://域名/prod 或 https://域名/api（与前端 VITE_API_URL 一致），勿写成裸 /pay（会 404）。
    'notify_domain' => 'https://api.fangzhou.uk/prod',
    // 商户网关完整基址（可选，优先级高于 notify_domain；如 https://admin.example.com/prod/pay）
    'pay_gateway_base' => '',
    // notify_domain 与 pay_gateway_base 均未配置时，按 Host + 此前缀 + /pay 回退（本地直连 8787 留空）
    'api_path_prefix' => '/prod',
    // 代付通道编码（系统兜底，勿依赖）：生产应由商户×通道 transfer_enabled 白名单选路（9.4.2 / 9.5.4）。
    // 仅当商户无代付授权绑定时回退本配置（须为 channel_biz IN 2,3 且已填 transfer_adapter）；留空且无绑定时报未配置。
    // 回调地址：{notify_domain}/pay/transferNotify/{通道编码}。
    'transfer_channel_code' => 'https://api.fangzhou.uk',
    // 商户服务端「程序化代付 API」(/pay/transfer)：
    //  - auto_threshold：自动放款金额阈值（元）。代付金额 <= 阈值自动调上游出款；> 阈值落「待审核」转后台人工下发。
    //    默认 '0'（保守）：所有 API 代付均需人工审核；按业务信任度上调（如 '50000' 表示 5万 MMK 以内自动放款）。
    'transfer_api' => [
        'auto_threshold' => '0',
    ],
    // 商户网关限流(Phase 7.2 OWASP 防超频/暴力)：按「商户号+路径」固定窗口计数。
    // enable=false 关闭；max<=0 视为不限流；Redis 异常失败放行不阻断支付。
    // 默认 60 次/60 秒/每商户每端点（足够正常下单，挡住异常超频）。
    'rate_limit' => [
        'enable' => true,
        'max'    => 60,
        'window' => 60,
    ],
    // 商户×通道日累计限额（Phase 9.2.3）：Redis 键 mc_day:{merchant_id}:{channel_id}:{Ymd}。
    // enable=false 关闭；day_limit=0 表示不限；Redis 异常时 fail-close 拒单（仅日限模块）。
    'day_limit' => [
        'enable' => true,
    ],
    // 路由内通道分配策略（Phase 9.3.2）：
    // weight=加权随机（默认）；round_robin=同一路由 Redis 队列公平轮询（键 route_rr:{route_id}）。
    'route_pick_strategy' => 'weight',
    // 商户门户「API 对接」页提供的 PHP Demo 压缩包（置于 server/public/，经反代静态访问）
    // 下载 URL 默认 {notify_domain}/merchant-php.zip；可填 php_demo_download_url 覆盖完整地址
    'php_demo_filename'     => 'merchant-php.zip',
    'php_demo_download_url' => '',
    // 后台测试下单默认 notify_url（可被请求体覆盖；留空则自动 {notify_domain}/pay/test/notify）
    'test_notify_url' => '',
    // 测试商户 notify 接收器（/pay/test/notify）：验签、记日志、回应 SUCCESS 闭环联调
    'test_notify' => [
        'enable'              => true,
        'max_logs'            => 100,
        // true=验签失败也回 SUCCESS（仅本地排障）；生产联调应保持 false
        'accept_invalid_sign' => false,
    ],
];
