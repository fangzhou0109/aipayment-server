<?php
/**
 * 商户对接配置（复制本文件为 config.php 后填写）
 *
 * 切勿将 config.php 提交到公开仓库。
 */
return [
    // 平台支付网关基址，必须以 /pay 结尾。
    // nginx 反代时须带前缀（与商户门户 VITE_API_URL 一致），例如：
    //   https://api.starfusionx.com/prod/pay  或  https://api.starfusionx.com/api/pay
    // 错误示例：https://api.starfusionx.com/pay（无前缀会 404）
    'gateway_base' => 'https://api.starfusionx.com/prod/pay',

    // 商户号与 MD5 密钥（商户门户 → API 密钥）
    'mch_id'     => 'YOUR_MCH_ID',
    'secret_key' => 'YOUR_SECRET_KEY',

    // 签名类型：1=MD5（推荐入门），2=RSA（须配置下方 RSA 密钥）
    'sign_type' => 1,

    // sign_type=2 时：商户私钥（仅留在商户服务器，用于请求签名）
    'rsa_private_key' => '',

    // sign_type=2 时：平台 RSA 公钥（商户门户下载，用于验异步通知）
    'platform_rsa_public_key' => '',

    // 异步/同步回调（须公网可访问；本地调试可用内网穿透）
    'notify_url' => 'https://your-merchant-domain.com/demo/merchant-php/notify_url.php',
    'return_url' => 'https://your-merchant-domain.com/demo/merchant-php/return_url.php',

    // 代付（提现）异步回调：平台出款成功/失败后通知此地址（须公网可访问）。
    // 也可在每次 /pay/transfer 请求中用 notify_url 参数覆盖。
    'transfer_notify_url' => 'https://your-merchant-domain.com/demo/merchant-php/transfer_notify_url.php',

    // 默认支付类型 pay_type：1支付宝PC 2支付宝H5 3微信PC 4微信H5 5银联快捷 6银联扫码 7其他
    'default_pay_type' => 3,
];
