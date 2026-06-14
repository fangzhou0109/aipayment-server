<?php
/**
 * 商户异步通知接收 Demo
 *
 * 平台 POST 表单字段：order_id, order_no, money, mch_id, extra, status, time, sign, sign_type
 * 验签通过后处理业务（更新订单状态等），并响应纯文本 SUCCESS。
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
demo_require_config();

$payload = $_POST;
$sign = (string) ($payload['sign'] ?? '');
$signType = (int) ($payload['sign_type'] ?? PaySign::SIGN_TYPE_MD5);
$secretKey = (string) demo_config('secret_key', '');
$platformPublic = (string) demo_config('platform_rsa_public_key', '');

$verifyOk = PaySign::verify(
    $payload,
    $secretKey,
    $signType,
    $sign,
    $signType === PaySign::SIGN_TYPE_RSA ? $platformPublic : null
);

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logLine = sprintf(
    "[%s] sign_ok=%s order_id=%s order_no=%s money=%s status=%s ip=%s\n",
    date('Y-m-d H:i:s'),
    $verifyOk ? '1' : '0',
    (string) ($payload['order_id'] ?? ''),
    (string) ($payload['order_no'] ?? ''),
    (string) ($payload['money'] ?? ''),
    (string) ($payload['status'] ?? ''),
    $_SERVER['REMOTE_ADDR'] ?? '-'
);
@file_put_contents($logDir . '/notify.log', $logLine . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

header('Content-Type: text/plain; charset=utf-8');

if (!$verifyOk) {
    echo 'FAIL';
    exit;
}

// TODO: 在此处更新商户侧订单为已支付（须幂等：同一 order_no 只处理一次）

echo 'SUCCESS';
