<?php
// +----------------------------------------------------------------------
// | SaiPayment 商户网关 Postman 探针
// +----------------------------------------------------------------------
// | 用法（在 server 目录）：
// |   # ① 对接【本平台】商户网关（推荐，与 LQPAY 上游同协议，先测通自己）
// |   php plugin/paymentchannel/scripts/pay_gateway_postman_probe.php self [mch_id] [pay_type] [money分]
// |   示例：php ... pay_gateway_postman_probe.php self TEST_M001 3 10000
// |
// |   # ② 对接【上游通道】网关（通道表 upstream 凭证，排障 LQPAY 时用）
// |   php plugin/paymentchannel/scripts/pay_gateway_postman_probe.php upstream [channel_id]
// |   示例：php ... pay_gateway_postman_probe.php upstream 14
// +----------------------------------------------------------------------

declare(strict_types=1);

use plugin\paymentchannel\service\SignService;

$appRoot = dirname(__DIR__, 3);
require $appRoot . '/vendor/autoload.php';

$mode = strtolower(trim((string) ($argv[1] ?? 'self')));
if (!in_array($mode, ['self', 'upstream'], true)) {
    fwrite(STDERR, "用法: php pay_gateway_postman_probe.php self|upstream ...\n");
    exit(1);
}

[$pdo, $appCfg] = bootstrapDbAndConfig($appRoot);

if ($mode === 'self') {
    runSelfPlatformProbe($pdo, $appCfg, $argv);
} else {
    runUpstreamProbe($pdo, $appCfg, $argv);
}

/**
 * @return array{0:PDO,1:array}
 */
function bootstrapDbAndConfig(string $appRoot): array
{
    $envFile = $appRoot . '/.env';
    if (!is_file($envFile)) {
        fwrite(STDERR, "缺少 .env\n");
        exit(1);
    }
    $cfg = [];
    foreach (file($envFile) as $line) {
        if (preg_match('/^\s*(DB_[A-Z_]+)\s*=\s*(.*)$/', trim($line), $m)) {
            $cfg[$m[1]] = trim($m[2]);
        }
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['DB_HOST'], $cfg['DB_PORT'], $cfg['DB_NAME']),
        $cfg['DB_USER'],
        $cfg['DB_PASSWORD'],
    );

    $appCfg = [];
    $appPhp = $appRoot . '/plugin/paymentchannel/config/app.php';
    if (is_file($appPhp)) {
        $appCfg = include $appPhp;
    }

    return [$pdo, is_array($appCfg) ? $appCfg : []];
}

function resolveNotifyDomain(array $appCfg): string
{
    $domain = trim((string) ($appCfg['notify_domain'] ?? ''));
    if ($domain !== '') {
        return rtrim($domain, '/');
    }
    $prefix = trim((string) ($appCfg['api_path_prefix'] ?? '/prod'), '/');
    return 'https://api.fangzhou.uk/' . $prefix;
}

/**
 * 本平台商户网关：模拟外部商户 Postman 调 /pay/submitOrder
 */
function runSelfPlatformProbe(PDO $pdo, array $appCfg, array $argv): void
{
    $mchId = trim((string) ($argv[2] ?? 'TEST_M001'));
    $payType = (int) ($argv[3] ?? 3);
    $moneyCents = trim((string) ($argv[4] ?? '10000'));

    $stmt = $pdo->prepare('SELECT id,mch_id,name,secret_key,status FROM sa_pay_merchant WHERE mch_id = ? LIMIT 1');
    $stmt->execute([$mchId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$merchant || (int) $merchant['status'] !== 1) {
        fwrite(STDERR, "商户 {$mchId} 不存在或已停用\n");
        exit(1);
    }

    $secretKey = (string) $merchant['secret_key'];
    if ($secretKey === '') {
        fwrite(STDERR, "商户 {$mchId} 缺少 secret_key\n");
        exit(1);
    }

    $notifyDomain = resolveNotifyDomain($appCfg);
    $gatewayBase = $notifyDomain . '/pay';
    $notifyUrl = trim((string) ($appCfg['test_notify_url'] ?? ''));
    if ($notifyUrl === '') {
        $notifyUrl = $notifyDomain . '/pay/test/notify';
    }

    $orderId = 'POSTMAN' . date('YmdHis') . random_int(1000, 9999);
    $params = buildGatewayParams($mchId, $payType, $moneyCents, $orderId, $notifyUrl);
    $params['sign'] = SignService::makeSign($params, $secretKey, SignService::SIGN_TYPE_MD5);
    $params['sign_type'] = (string) SignService::SIGN_TYPE_MD5;

    $bindings = loadMerchantChannelBindings($pdo, (int) $merchant['id'], $payType);
    $routes = loadRoutesForPayType($pdo, $payType);

    printProbeOutput([
        'title'       => '本平台商户网关 Postman 对接数据',
        'scenario'    => '模拟外部商户直连本系统 /pay/*（与 LQPAY 上游协议相同，先测通本平台再对接上游）',
        'submit_url'  => $gatewayBase . '/submitOrder',
        'query_url'   => $gatewayBase . '/query',
        'mch_id'      => $mchId,
        'merchant_id' => (int) $merchant['id'],
        'merchant_name' => (string) $merchant['name'],
        'secret_hint' => maskSecret($secretKey),
        'pay_type'    => $payType,
        'params'      => $params,
        'secret_key'  => $secretKey,
        'extra_info'  => [
            'notify_domain' => $notifyDomain,
            '商户代收通道绑定 (pay_type=' . $payType . ')' => $bindings,
            '综合路由 (pay_type=' . $payType . ')' => $routes,
        ],
        'expect' => [
            'code=200 + pay_url' => '本平台网关 + 路由 + 上游适配器全链路正常',
            '签名校验失败' => 'Postman 参数与 sign 不一致，或 secret_key 错误',
            '商户未配置可用支付通道' => '商户未绑定该 pay_type 的代收通道（sa_pay_merchant_channel）',
            '上游下单失败：签名校验失败' => '本平台已过网关，问题在通道 upstream_key（曾用 toArray 丢密钥，需 reload）',
            '上游下单失败：无可用支付通道' => '签名已过，LQPAY 侧 TEST_M001 未绑通道',
        ],
    ]);
}

/**
 * 上游通道网关：用通道表 upstream 凭证直连 LQPAY（排障专用）
 */
function runUpstreamProbe(PDO $pdo, array $appCfg, array $argv): void
{
    $channelId = (int) ($argv[2] ?? 14);
    $stmt = $pdo->prepare('SELECT * FROM sa_pay_channel WHERE id = ? AND status = 1 LIMIT 1');
    $stmt->execute([$channelId]);
    $channel = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$channel) {
        fwrite(STDERR, "通道 id={$channelId} 不存在或已停用\n");
        exit(1);
    }

    $mchId = (string) $channel['upstream_mch_id'];
    $secretKey = (string) $channel['upstream_key'];
    $gatewayBase = rtrim((string) $channel['gateway_url'], '/');
    $payType = (int) $channel['pay_type'];
    $channelCode = (string) ($channel['code'] ?? '');

    if ($mchId === '' || $secretKey === '' || $gatewayBase === '') {
        fwrite(STDERR, "通道缺少 upstream_mch_id / upstream_key / gateway_url\n");
        exit(1);
    }

    $notifyDomain = resolveNotifyDomain($appCfg);
    $notifyUrl = $notifyDomain . '/pay/notify/' . $channelCode;
    $orderId = 'UPSTREAM' . date('YmdHis') . random_int(1000, 9999);

    $params = buildGatewayParams($mchId, $payType, '10000', $orderId, $notifyUrl, '上游通道探针');
    $params['extra'] = 'upstream_probe';
    $params['sign'] = SignService::makeSign($params, $secretKey, SignService::SIGN_TYPE_MD5);
    $params['sign_type'] = (string) SignService::SIGN_TYPE_MD5;

    printProbeOutput([
        'title'       => '上游通道 Postman 对接数据（排障）',
        'scenario'    => "绕过本平台，用通道 id={$channelId} 凭证直连 {$gatewayBase}",
        'submit_url'  => $gatewayBase . '/submitOrder',
        'query_url'   => $gatewayBase . '/query',
        'mch_id'      => $mchId,
        'channel_code'=> $channelCode,
        'channel_title'=> (string) ($channel['title'] ?? ''),
        'adapter'     => (string) ($channel['adapter'] ?? ''),
        'secret_hint' => maskSecret($secretKey),
        'pay_type'    => $payType,
        'params'      => $params,
        'secret_key'  => $secretKey,
        'extra_info'  => [
            '说明' => '仅用于验证 upstream_key 与 LQPAY 商户 secret 是否一致',
        ],
        'expect' => [
            'code=200' => '上游配置正常',
            '签名校验失败' => 'upstream_key ≠ 上游商户 secret_key',
            '无可用支付通道' => '上游商户未绑定 pay_type=' . $payType,
        ],
    ]);
}

/**
 * 与商户 Demo / LqpayAdapter 一致的网关参数字段
 */
function buildGatewayParams(
    string $mchId,
    int $payType,
    string $moneyCents,
    string $orderId,
    string $notifyUrl,
    string $commodityName = 'Postman对接测试',
): array {
    return [
        'mch_id'         => $mchId,
        'pay_type'       => (string) $payType,
        'money'          => $moneyCents,
        'time'           => (string) time(),
        'order_id'       => $orderId,
        'notify_url'     => $notifyUrl,
        'return_url'     => '',
        'commodity_name' => $commodityName,
        'extra'          => 'postman_probe',
        'client_ip'      => '127.0.0.1',
    ];
}

function loadMerchantChannelBindings(PDO $pdo, int $merchantId, int $payType): array
{
    $sql = 'SELECT mc.channel_id, c.code, c.title, c.adapter, c.pay_type, c.money_rule, mc.status
            FROM sa_pay_merchant_channel mc
            JOIN sa_pay_channel c ON c.id = mc.channel_id
            WHERE mc.merchant_id = ? AND mc.status = 1 AND c.status = 1
            AND c.channel_biz IN (1,3) AND c.pay_type = ?
            ORDER BY mc.id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$merchantId, $payType]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [['提示' => '无绑定 → 网关将返回「商户未配置可用支付通道」']];
}

function loadRoutesForPayType(PDO $pdo, int $payType): array
{
    $sql = 'SELECT r.id AS route_id, r.title, rc.channel_id, c.code, c.adapter, rc.weight
            FROM sa_pay_route r
            JOIN sa_pay_route_channel rc ON rc.route_id = r.id
            JOIN sa_pay_channel c ON c.id = rc.channel_id
            WHERE r.status = 1 AND r.pay_type = ? AND c.status = 1
            ORDER BY r.id, rc.weight DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$payType]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $rows ?: [['提示' => '无综合路由 → 走商户直连通道绑定']];
}

function maskSecret(string $key): string
{
    $len = strlen($key);
    if ($len <= 8) {
        return '***';
    }
    return substr($key, 0, 4) . '***' . substr($key, -4) . " (len={$len})";
}

/**
 * @param array<string,mixed> $ctx
 */
function printProbeOutput(array $ctx): void
{
    $params = $ctx['params'];
    $secretKey = (string) $ctx['secret_key'];
    $unsigned = $params;
    unset($unsigned['sign'], $unsigned['sign_type']);
    $signString = SignService::buildSignString($unsigned, $secretKey);
    // 日志中掩码密钥，避免待签串泄露 secret_key
    $signStringSafe = preg_replace('/&key=.*$/', '&key=' . ($ctx['secret_hint'] ?? '***'), $signString) ?? $signString;

    echo '========== ' . $ctx['title'] . " ==========\n";
    echo '场景        : ' . $ctx['scenario'] . "\n";
    echo '下单 URL    : ' . $ctx['submit_url'] . "\n";
    echo '查单 URL    : ' . $ctx['query_url'] . "\n";
    echo '商户号      : ' . $ctx['mch_id'] . "\n";
    if (isset($ctx['merchant_name'])) {
        echo '商户名称    : ' . $ctx['merchant_name'] . ' (id=' . $ctx['merchant_id'] . ")\n";
    }
    if (isset($ctx['channel_code'])) {
        echo '通道编码    : ' . $ctx['channel_code'] . ' / ' . ($ctx['channel_title'] ?? '') . "\n";
        echo '适配器      : ' . ($ctx['adapter'] ?? '') . "\n";
    }
    echo 'pay_type    : ' . $ctx['pay_type'] . "\n";
    echo '密钥        : ' . $ctx['secret_hint'] . "\n";
    echo '待签串      : ' . $signStringSafe . "\n";
    echo '签名 sign   : ' . $params['sign'] . "\n";

    foreach ($ctx['extra_info'] as $label => $data) {
        echo "\n----- {$label} -----\n";
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }

    echo "\n----- Postman Body (x-www-form-urlencoded) -----\n";
    foreach ($params as $k => $v) {
        echo "{$k}={$v}\n";
    }

    echo "\n----- Postman 导入用 JSON（复制到 Body raw JSON 后改 form 模式对照） -----\n";
    echo json_encode($params, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

    echo "\n----- curl 一键测试 -----\n";
    $curlParts = [];
    foreach ($params as $k => $v) {
        $curlParts[] = '-d ' . escapeshellarg($k . '=' . $v);
    }
    echo 'curl -s -X POST ' . escapeshellarg((string) $ctx['submit_url']) . ' ' . implode(' ', $curlParts) . "\n";

    echo "\n----- 预期响应 -----\n";
    foreach ($ctx['expect'] as $k => $v) {
        echo "  {$k} → {$v}\n";
    }
    echo "================================================\n";
}
