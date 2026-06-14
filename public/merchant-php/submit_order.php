<?php
/**
 * 代收下单 Demo — POST /pay/submitOrder
 *
 * 浏览器访问本文件将发起一笔测试订单并输出 JSON。
 * 也可在业务代码中 require 本目录 lib 后自行组装参数。
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
demo_require_config();

$input = array_merge($_GET, $_POST);
$moneyYuan = isset($input['amount']) ? (float) $input['amount'] : 1.00;
$moneyCents = (string) (int) round($moneyYuan * 100);
if ((int) $moneyCents <= 0) {
    demo_json_response(['ok' => false, 'message' => '金额须大于 0'], 400);
    exit;
}

$orderId = trim((string) ($input['order_id'] ?? ''));
if ($orderId === '') {
    $orderId = 'DEMO' . date('YmdHis') . random_int(1000, 9999);
}

$params = [
    'mch_id'         => (string) demo_config('mch_id'),
    'pay_type'       => (string) ((int) ($input['pay_type'] ?? demo_config('default_pay_type', 3))),
    'money'          => $moneyCents,
    'time'           => (string) time(),
    'order_id'       => $orderId,
    'return_url'     => trim((string) ($input['return_url'] ?? '')) ?: (string) demo_config('return_url'),
    'commodity_name' => (string) ($input['commodity_name'] ?? 'PHP Demo 测试商品'),
    'extra'          => (string) ($input['extra'] ?? 'php_demo'),
    'notify_url'     => trim((string) ($input['notify_url'] ?? '')) ?: (string) demo_config('notify_url'),
    'client_ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
];

$params = demo_sign_params($params);

$url = rtrim((string) demo_config('gateway_base'), '/') . '/submitOrder';

try {
    $raw = HttpClient::postForm($url, $params);
    if (demo_is_html_gateway_error($raw)) {
        demo_json_response([
            'ok'      => false,
            'message' => demo_gateway_misconfig_hint(rtrim((string) demo_config('gateway_base'), '/')),
            'request_url' => $url,
            'response_raw' => $raw,
        ], 502);
        exit;
    }
    $decoded = json_decode($raw, true);
    $unsigned = $params;
    unset($unsigned['sign'], $unsigned['sign_type']);
    demo_json_response([
        'ok'           => true,
        'request_url'  => $url,
        'request'      => $params,
        'sign_string'  => PaySign::buildSignString($unsigned, (string) demo_config('secret_key')),
        'response_raw' => $raw,
        'response'     => $decoded,
        'pay_url'      => is_array($decoded) ? ($decoded['data']['pay_url'] ?? null) : null,
    ]);
} catch (Throwable $e) {
    demo_json_response([
        'ok'      => false,
        'message' => $e->getMessage(),
        'request' => $params,
    ], 500);
}
