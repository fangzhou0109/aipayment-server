<?php
/**
 * 代付（提现）异步通知接收 Demo
 *
 * 平台出款成功/失败后 POST 表单字段：
 *   out_biz_no  商户代付单号
 *   transfer_no 平台代付单号
 *   money       金额（分）
 *   mch_id      商户号
 *   status      success（出款成功）| fail（出款失败）
 *   reason      失败原因（status=fail 时可能附带）
 *   time        时间戳
 *   sign / sign_type 签名
 *
 * 验签通过后处理业务（更新提现订单状态等），并响应纯文本 SUCCESS。
 * 处理须【幂等】：同一 out_biz_no/transfer_no 只入账一次。
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
    "[%s] sign_ok=%s out_biz_no=%s transfer_no=%s money=%s status=%s reason=%s ip=%s\n",
    date('Y-m-d H:i:s'),
    $verifyOk ? '1' : '0',
    (string) ($payload['out_biz_no'] ?? ''),
    (string) ($payload['transfer_no'] ?? ''),
    (string) ($payload['money'] ?? ''),
    (string) ($payload['status'] ?? ''),
    (string) ($payload['reason'] ?? ''),
    $_SERVER['REMOTE_ADDR'] ?? '-'
);
@file_put_contents($logDir . '/transfer_notify.log', $logLine . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

header('Content-Type: text/plain; charset=utf-8');

if (!$verifyOk) {
    echo 'FAIL';
    exit;
}

// TODO: 在此处更新商户侧提现订单状态（success=已出款 / fail=出款失败需退款给用户）
//       须幂等：同一 out_biz_no 只处理一次。

echo 'SUCCESS';
