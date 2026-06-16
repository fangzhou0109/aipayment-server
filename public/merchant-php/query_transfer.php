<?php
/**
 * 代付（提现）查单 Demo — POST /pay/transferQuery
 *
 * 用法：query_transfer.php?out_biz_no=商户代付单号
 * 仅能查询本商户名下代付单（mch_id 强约束）。
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
demo_require_config();

$outBizNo = trim((string) ($_GET['out_biz_no'] ?? $_POST['out_biz_no'] ?? ''));
if ($outBizNo === '') {
    demo_json_response(['ok' => false, 'message' => '请传 out_biz_no（商户代付单号）'], 400);
    exit;
}

$params = [
    'mch_id'     => (string) demo_config('mch_id'),
    'out_biz_no' => $outBizNo,
    'time'       => (string) time(),
    'client_ip'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
];

$params = demo_sign_params($params);
$url = rtrim((string) demo_config('gateway_base'), '/') . '/transferQuery';

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
        'status'       => is_array($decoded) ? ($decoded['data']['status_text'] ?? null) : null,
    ]);
} catch (Throwable $e) {
    demo_json_response(['ok' => false, 'message' => $e->getMessage()], 500);
}
