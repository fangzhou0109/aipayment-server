<?php
/**
 * 代付（提现）下单 Demo — POST /pay/transfer
 *
 * 下游商户服务器调用本接口，给下游用户出款（提现）。
 * 与代收下单同一套鉴权（mch_id + time + sign），out_biz_no 为同商户幂等键：
 * 重复提交相同 out_biz_no 不会重复出款，仅返回既有单据状态。
 *
 * 收款人二选一：
 *   ① 直传收款人信息（推荐，下游用户提现）：account_no 必填，account_name 可选
 *      （部分场景如缅甸钱包/手机号代付无需姓名），可带 bank_name/bank_code/
 *      branch_name/account_phone，每单收款人都不同；
 *   ② 预绑 bank_card_id：商户在门户「银行卡」绑定的自有收款卡 ID（兼容老用法）。
 *
 * 浏览器访问本文件将发起一笔测试代付并输出 JSON；也可在业务代码中 require lib 后自行组装。
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
demo_require_config();

$input = array_merge($_GET, $_POST);

// 金额：表单填「元」，转为「分」字符串（与代收 money 口径一致，须为正整数）
$moneyYuan = isset($input['amount']) ? (float) $input['amount'] : 1.00;
$moneyCents = (string) (int) round($moneyYuan * 100);
if ((int) $moneyCents <= 0) {
    demo_json_response(['ok' => false, 'message' => '代付金额须大于 0'], 400);
    exit;
}

// 商户代付单号（幂等键）：留空自动生成
$outBizNo = trim((string) ($input['out_biz_no'] ?? ''));
if ($outBizNo === '') {
    $outBizNo = 'DEMOT' . date('YmdHis') . random_int(1000, 9999);
}

$params = [
    'mch_id'     => (string) demo_config('mch_id'),
    'out_biz_no' => $outBizNo,
    'money'      => $moneyCents,
    'notify_url' => trim((string) ($input['notify_url'] ?? '')) ?: (string) demo_config('transfer_notify_url'),
    'time'       => (string) time(),
    'client_ip'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
];

// 收款人：优先直传收款人信息（下游用户每单不同），否则回退预绑 bank_card_id
$bankCardId = (int) ($input['bank_card_id'] ?? 0);
$accountName = trim((string) ($input['account_name'] ?? ''));
$accountNo   = trim((string) ($input['account_no'] ?? ''));
if ($accountNo !== '') {
    // account_no（账号/钱包号/手机号）必填；account_name 可选（如缅甸钱包代付无需姓名）
    $params['account_no'] = $accountNo;
    if ($accountName !== '') {
        $params['account_name'] = $accountName;
    }
    foreach (['bank_name', 'bank_code', 'branch_name', 'account_phone'] as $k) {
        $v = trim((string) ($input[$k] ?? ''));
        if ($v !== '') {
            $params[$k] = $v;
        }
    }
} elseif ($bankCardId > 0) {
    $params['bank_card_id'] = (string) $bankCardId;
} else {
    demo_json_response(['ok' => false, 'message' => '请填写收款账号 account_no（姓名可选）或预绑卡 bank_card_id'], 400);
    exit;
}

$params = demo_sign_params($params);

$url = rtrim((string) demo_config('gateway_base'), '/') . '/transfer';

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
        // 代付单状态在 data 内：pending(待审核)/approved/paying(代付中)/success/fail/rejected
        'status'       => is_array($decoded) ? ($decoded['data']['status_text'] ?? null) : null,
    ]);
} catch (Throwable $e) {
    demo_json_response([
        'ok'      => false,
        'message' => $e->getMessage(),
        'request' => $params,
    ], 500);
}
