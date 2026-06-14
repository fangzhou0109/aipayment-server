<?php
// +----------------------------------------------------------------------
// | 后台 testSubmit 探针（对比商户网关 /pay/submitOrder）
// +----------------------------------------------------------------------

declare(strict_types=1);

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\OrderLogic;
use plugin\paymentchannel\app\logic\PayGatewayLogic;
use plugin\paymentchannel\service\SignService;
use plugin\paymentchannel\service\TestNotifyService;

$serverRoot = dirname(__DIR__, 3);
chdir($serverRoot);
require_once $serverRoot . '/vendor/autoload.php';
require_once $serverRoot . '/support/bootstrap.php';

$merchantId = (int) ($argv[1] ?? 1);
$payType = (int) ($argv[2] ?? 3);
$amount = (string) ($argv[3] ?? '100');
$channelId = (int) ($argv[4] ?? 0);

echo "=== admin testSubmit probe merchant_id={$merchantId} pay_type={$payType} amount={$amount} ===\n";

// 1) OrderLogic::testSubmit（后台路径）
try {
    $orderLogic = new OrderLogic();
    $params = [
        'amount'         => $amount,
        'pay_type'       => $payType,
        'commodity_name' => '后台测试下单',
        'client_ip'      => '127.0.0.1',
        'extra'          => 'admin_probe',
    ];
    if ($channelId > 0) {
        $params['channel_id'] = $channelId;
    }
    $r = $orderLogic->testSubmit($merchantId, $params);
    echo "[testSubmit] OK order_no=" . ($r['order_no'] ?? '') . ' channel_id=' . ($r['channel_id'] ?? '') . "\n";
    echo '[testSubmit] pay_url=' . ($r['pay_url'] ?? '') . "\n";
} catch (PaymentException $e) {
    echo '[testSubmit] FAIL ' . $e->getMessage() . "\n";
}

// 2) 商户网关等价参数（Demo 路径）
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        config('database.connections.mysql.host'),
        config('database.connections.mysql.port'),
        config('database.connections.mysql.database'),
    ),
    config('database.connections.mysql.username'),
    config('database.connections.mysql.password'),
);
$m = $pdo->query("SELECT id,mch_id,secret_key,status,rate,single_min,single_max,name FROM sa_pay_merchant WHERE id={$merchantId}")->fetch(PDO::FETCH_ASSOC);
if (!$m) {
    fwrite(STDERR, "merchant not found\n");
    exit(1);
}

$orderId = 'PROBE' . date('YmdHis') . random_int(1000, 9999);
$notifyUrl = TestNotifyService::resolveDefaultNotifyUrl();
$moneyCents = (string) (int) bcmul((string) $amount, '100', 0);
$gwParams = [
    'order_id'       => $orderId,
    'money'          => $moneyCents,
    'pay_type'       => $payType,
    'notify_url'     => $notifyUrl,
    'return_url'     => '',
    'commodity_name' => '后台测试下单',
    'extra'          => 'admin_probe',
    'client_ip'      => '127.0.0.1',
];
if ($channelId > 0) {
    $gwParams['_force_channel_id'] = $channelId;
}

try {
    $merchant = [
        'id'         => (int) $m['id'],
        'mch_id'     => (string) $m['mch_id'],
        'name'       => (string) $m['name'],
        'status'     => (int) $m['status'],
        'rate'       => (string) $m['rate'],
        'single_min' => (string) $m['single_min'],
        'single_max' => (string) $m['single_max'],
    ];
    $r2 = (new PayGatewayLogic())->submitOrder($merchant, $gwParams);
    echo "[gateway] OK order_no=" . ($r2['order_no'] ?? '') . "\n";
} catch (PaymentException $e) {
    echo '[gateway] FAIL ' . $e->getMessage() . "\n";
}

// 3) 带签名的 /pay/submitOrder 模拟（完整 Demo 路径）
$signed = $gwParams;
$signed['mch_id'] = (string) $m['mch_id'];
$signed['time'] = (string) time();
$signed['order_id'] = 'SIGN' . date('YmdHis') . random_int(1000, 9999);
$signed['sign'] = SignService::makeSign($signed, (string) $m['secret_key'], SignService::SIGN_TYPE_MD5);
$signed['sign_type'] = SignService::SIGN_TYPE_MD5;
unset($signed['_force_channel_id']);

$base = rtrim((string) config('plugin.paymentchannel.app.notify_domain', ''), '/') . '/pay/submitOrder';
$ch = curl_init($base);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($signed),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$raw = curl_exec($ch);
curl_close($ch);
echo "[http /pay/submitOrder] " . $raw . "\n";
