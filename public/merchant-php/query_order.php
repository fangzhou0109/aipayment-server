<?php
/**
 * 代收查单 Demo — POST /pay/query
 *
 * 用法：query_order.php?order_id=商户订单号
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
demo_require_config();

$orderId = trim((string) ($_GET['order_id'] ?? $_POST['order_id'] ?? ''));
if ($orderId === '') {
    demo_json_response(['ok' => false, 'message' => '请传 order_id（商户订单号）'], 400);
    exit;
}

$params = [
    'mch_id'   => (string) demo_config('mch_id'),
    'order_id' => $orderId,
    'time'     => (string) time(),
    'client_ip'=> $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
];

$params = demo_sign_params($params);
$url = rtrim((string) demo_config('gateway_base'), '/') . '/query';

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
    demo_json_response([
        'ok'           => true,
        'request_url'  => $url,
        'request'      => $params,
        'response_raw' => $raw,
        'response'     => $decoded,
    ]);
} catch (Throwable $e) {
    demo_json_response(['ok' => false, 'message' => $e->getMessage()], 500);
}
