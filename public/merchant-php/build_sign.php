<?php
/**
 * 生成 MD5 签名示例（待签串 + curl）
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

demo_require_config();

$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? 'submit'));
$gatewayBase = rtrim((string) demo_config('gateway_base'), '/');
$mchId = (string) demo_config('mch_id');
$secretKey = (string) demo_config('secret_key');

if ($mchId === '' || $secretKey === '') {
    demo_json_response(['ok' => false, 'message' => 'mch_id 或 secret_key 未配置'], 400);
    exit;
}

$signType = (int) demo_config('sign_type', PaySign::SIGN_TYPE_MD5);
if ($signType === PaySign::SIGN_TYPE_RSA) {
    demo_json_response(['ok' => false, 'message' => '本工具暂仅支持 MD5 签名示例，RSA 请在业务代码中使用商户私钥'], 400);
    exit;
}

if ($action === 'query') {
    $orderId = trim((string) ($_POST['order_id'] ?? $_GET['order_id'] ?? ''));
    if ($orderId === '') {
        demo_json_response(['ok' => false, 'message' => '请填写商户订单号'], 400);
        exit;
    }
    $params = [
        'mch_id'    => $mchId,
        'order_id'  => $orderId,
        'time'      => (string) time(),
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ];
    $url = $gatewayBase . '/query';
} else {
    $amountYuan = (float) ($_POST['amount'] ?? $_GET['amount'] ?? 1);
    $moneyCents = (string) max(1, (int) round($amountYuan * 100));
    $orderId = trim((string) ($_POST['order_id'] ?? $_GET['order_id'] ?? ''));
    if ($orderId === '') {
        $orderId = 'DEMO' . date('YmdHis') . random_int(1000, 9999);
    }
    $params = [
        'mch_id'         => $mchId,
        'pay_type'       => (string) ((int) ($_POST['pay_type'] ?? $_GET['pay_type'] ?? demo_config('default_pay_type', 3))),
        'money'          => $moneyCents,
        'order_id'       => $orderId,
        'notify_url'     => (string) demo_config('notify_url'),
        'return_url'     => (string) demo_config('return_url'),
        'commodity_name' => (string) ($_POST['commodity_name'] ?? 'PHP Demo 测试商品'),
        'extra'          => (string) ($_POST['extra'] ?? 'php_demo'),
        'client_ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'time'           => (string) time(),
    ];
    $url = $gatewayBase . '/submitOrder';
}

$params['sign_type'] = PaySign::SIGN_TYPE_MD5;
$signString = PaySign::buildSignString($params, $secretKey);
$params['sign'] = PaySign::makeSign($params, $secretKey, PaySign::SIGN_TYPE_MD5);

demo_json_response([
    'ok'           => true,
    'action'       => $action,
    'url'          => $url,
    'sign_string'  => $signString,
    'params'       => $params,
    'curl_example' => demo_build_curl_example($url, $params),
]);
