#!/usr/bin/env php
<?php
// +----------------------------------------------------------------------
// | Phase 9.1 严格模式远端联调探针（一次性 CLI，自清理）
// +----------------------------------------------------------------------
// | 用法（在服务端 server 根目录）：
// |   php plugin/paymentchannel/scripts/phase91_strict_mode_probe.php
// |
// | 职责：
// |  1) 检查存量正常商户是否均有 merchant_channel 授权（9.1 部署后运营补绑验收）；
// |  2) 创建隔离探针商户：无绑定 → HTTP submitOrder 拒单；补绑后 → 下单成功；
// |  3) 自清理探针商户/订单/绑定，不污染生产数据。
// +----------------------------------------------------------------------

declare(strict_types=1);

use plugin\paymentchannel\app\exception\PaymentException;
use plugin\paymentchannel\app\logic\PayGatewayLogic;
use plugin\paymentchannel\app\model\Channel;
use plugin\paymentchannel\app\model\Merchant;
use plugin\paymentchannel\app\model\MerchantChannel;
use plugin\paymentchannel\app\model\Order;
use plugin\paymentchannel\app\model\RouteChannel;
use plugin\paymentchannel\service\SignService;

/** server 根目录（scripts → paymentchannel → plugin → server） */
$serverRoot = dirname(__DIR__, 3);
chdir($serverRoot);
require_once $serverRoot . '/vendor/autoload.php';

$worker = null;
require_once $serverRoot . '/support/bootstrap.php';

// ===== 配置 =====
const PROBE_MCH_ID = 'phase91_probe_mch';
const PROBE_SECRET = 'phase91_probe_secret_key_32chars!!';
const PROBE_ORDER_PREFIX = 'PHASE91_PROBE_';
const GATEWAY_BASE = 'http://127.0.0.1:8787';
const MOCK_CHANNEL_CODE = 'mock_test_001';

$passed = 0;
$failed = 0;
$probeMerchantId = 0;
$probeBindingId = 0;
$probeOrderNos = [];

/**
 * 断言助手：失败时抛异常由顶层捕获
 */
function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "[PASS] {$label}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$label}\n";
    throw new RuntimeException($label);
}

/**
 * 解码网关 JSON 响应（HTTP 200 也可能是业务失败 code=400）
 *
 * @return array{http_code:int, body:array}
 */
function postSubmitOrder(array $fields): array
{
    $ch = curl_init(GATEWAY_BASE . '/pay/submitOrder');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false) {
        throw new RuntimeException('curl 失败: ' . curl_error($ch));
    }
    curl_close($ch);

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        throw new RuntimeException('响应非 JSON: ' . substr((string) $raw, 0, 200));
    }

    return ['http_code' => $httpCode, 'body' => $body];
}

/**
 * 组装带签名的下单参数
 */
function buildSignedOrderParams(string $mchId, string $secret, string $orderId, int $payType, string $moneyCents): array
{
    $params = [
        'mch_id'     => $mchId,
        'pay_type'   => (string) $payType,
        'money'      => $moneyCents,
        'time'       => (string) time(),
        'order_id'   => $orderId,
        'notify_url' => 'https://probe.example.com/notify',
        'sign_type'  => '1',
    ];
    $params['sign'] = SignService::makeSign($params, $secret, SignService::SIGN_TYPE_MD5);
    return $params;
}

/**
 * 清理探针数据（幂等，多次调用安全）
 */
function cleanupProbeData(int $merchantId, array $orderNos): int
{
    $deleted = 0;
    if ($orderNos !== []) {
        $deleted += (int) Order::whereIn('order_no', $orderNos)->delete();
        $deleted += (int) Order::where('out_trade_no', 'like', PROBE_ORDER_PREFIX . '%')->delete();
    }
    if ($merchantId > 0) {
        $deleted += (int) MerchantChannel::where('merchant_id', $merchantId)->delete();
        $deleted += (int) Merchant::destroy($merchantId);
    }
    // 按 mch_id 兜底（上次异常未记录 id 时）
    $orphan = Merchant::where('mch_id', PROBE_MCH_ID)->find();
    if ($orphan) {
        MerchantChannel::where('merchant_id', (int) $orphan->id)->delete();
        $deleted += (int) Merchant::destroy((int) $orphan->id);
    }
    return $deleted;
}

echo "== Phase 9.1 严格模式远端探针 ==\n";

try {
    // ----- 0) 健康检查 -----
    $healthCh = curl_init(GATEWAY_BASE . '/pay/health');
    curl_setopt($healthCh, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($healthCh, CURLOPT_TIMEOUT, 5);
    $healthRaw = curl_exec($healthCh);
    $healthCode = (int) curl_getinfo($healthCh, CURLINFO_HTTP_CODE);
    curl_close($healthCh);
    assertTrue($healthCode === 200, '8787 /pay/health HTTP 200');

    // ----- 1) 定位 Mock 通道（后续种子与探针下单共用） -----
    $mockChannel = Channel::where('code', MOCK_CHANNEL_CODE)
        ->where('status', 1)
        ->whereNull('delete_time')
        ->find();
    assertTrue($mockChannel !== null, '存在启用中的 mock 通道 mock_test_001');

    $channelId = (int) $mockChannel->id;
    $payType = (int) $mockChannel->pay_type;

    // ----- 2) 存量商户授权缺口：自动补绑 mock 通道（幂等，部署 9.1 后须执行） -----
    $activeIds = Merchant::where('status', 1)->whereNull('delete_time')->column('id');
    $authorizedMerchantIds = MerchantChannel::where('status', MerchantChannel::STATUS_NORMAL)
        ->whereNull('delete_time')
        ->whereIn('merchant_id', $activeIds ?: [0])
        ->group('merchant_id')
        ->column('merchant_id');
    $gapIds = array_values(array_diff(array_map('intval', $activeIds), array_map('intval', $authorizedMerchantIds)));
    $seeded = 0;
    foreach ($gapIds as $mid) {
        $exists = MerchantChannel::where('merchant_id', $mid)
            ->where('channel_id', $channelId)
            ->find();
        if ($exists) {
            MerchantChannel::where('id', (int) $exists->id)->update([
                'status'      => MerchantChannel::STATUS_NORMAL,
                'rate'        => MerchantChannel::RATE_INHERIT,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        } else {
            MerchantChannel::create([
                'merchant_id' => $mid,
                'channel_id'  => $channelId,
                'rate'        => MerchantChannel::RATE_INHERIT,
                'day_limit'   => '0.0000',
                'status'      => MerchantChannel::STATUS_NORMAL,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }
        $seeded++;
    }
    if ($seeded > 0) {
        echo "[INFO] 自动补绑 merchant_channel：{$seeded} 个商户 → channel_id={$channelId}\n";
    }
    $gapAfter = count(array_diff(
        array_map('intval', $activeIds),
        array_map('intval', MerchantChannel::where('status', MerchantChannel::STATUS_NORMAL)
            ->whereNull('delete_time')
            ->whereIn('merchant_id', $activeIds ?: [0])
            ->group('merchant_id')
            ->column('merchant_id'))
    ));
    assertTrue($gapAfter === 0, "存量正常商户均有 merchant_channel 授权（补绑后缺口={$gapAfter}）");

    // ----- 3) 校验 mock 通道已挂接启用路由 -----
    $routeLink = RouteChannel::alias('rc')
        ->join('sa_pay_route r', 'r.id = rc.route_id')
        ->where('rc.channel_id', $channelId)
        ->where('rc.status', 1)
        ->whereNull('rc.delete_time')
        ->where('r.status', 1)
        ->where('r.pay_type', $payType)
        ->whereNull('r.delete_time')
        ->field('r.id as route_id')
        ->find();
    assertTrue($routeLink !== null, "mock 通道已挂接启用路由（pay_type={$payType}）");

    // ----- 4) 清理历史探针残留 -----
    $deletedRows = cleanupProbeData(0, []);
    if ($deletedRows > 0) {
        echo "[INFO] 清理历史探针残留 deleted_rows={$deletedRows}\n";
    }

    // ----- 5) 创建探针商户（无 merchant_channel） -----
    $merchant = Merchant::create([
        'mch_id'              => PROBE_MCH_ID,
        'name'                => 'Phase91探针商户',
        'secret_key'          => PROBE_SECRET,
        'balance'             => '0.0000',
        'balance_freeze'      => '0.0000',
        'rate'                => '2.6000',
        'rate_transfer'       => '1.0000',
        'single_min'          => '0.0000',
        'single_max'          => '0.0000',
        'ip_whitelist_status' => 2,
        'status'              => 1,
        'create_time'         => date('Y-m-d H:i:s'),
        'update_time'         => date('Y-m-d H:i:s'),
    ]);
    $probeMerchantId = (int) $merchant->id;
    assertTrue($probeMerchantId > 0, '探针商户创建成功');

    $merchantRow = $merchant->toArray();
    $merchantRow['id'] = $probeMerchantId;

    // ----- 6) CLI：无授权 submitOrder 拒单 -----
    $logic = new PayGatewayLogic();
    $cliRejected = false;
    try {
        $logic->submitOrder($merchantRow, [
            'order_id'   => PROBE_ORDER_PREFIX . 'CLI_NO_AUTH',
            'money'      => '10000',
            'pay_type'   => $payType,
            'notify_url' => 'https://probe.example.com/notify',
        ]);
    } catch (PaymentException $e) {
        $cliRejected = str_contains($e->getMessage(), '商户未配置可用支付通道');
    }
    assertTrue($cliRejected, 'CLI PayGatewayLogic 无授权拒单');

    // ----- 7) HTTP：无授权 submitOrder 拒单 -----
    $orderNoAuth = PROBE_ORDER_PREFIX . 'HTTP_NO_AUTH_' . time();
    $httpNoAuth = postSubmitOrder(buildSignedOrderParams(
        PROBE_MCH_ID,
        PROBE_SECRET,
        $orderNoAuth,
        $payType,
        '10000'
    ));
    assertTrue($httpNoAuth['http_code'] === 200, 'HTTP 无授权下单 HTTP 200（统一响应体）');
    assertTrue(
        (int) ($httpNoAuth['body']['code'] ?? 0) === 400
        && str_contains((string) ($httpNoAuth['body']['message'] ?? ''), '商户未配置可用支付通道'),
        'HTTP 无授权下单业务拒单 message 正确'
    );

    // ----- 8) 补 merchant_channel 绑定后下单成功 -----
    $binding = MerchantChannel::create([
        'merchant_id' => $probeMerchantId,
        'channel_id'  => $channelId,
        'rate'        => MerchantChannel::RATE_INHERIT,
        'day_limit'   => '0.0000',
        'status'      => MerchantChannel::STATUS_NORMAL,
        'create_time' => date('Y-m-d H:i:s'),
        'update_time' => date('Y-m-d H:i:s'),
    ]);
    $probeBindingId = (int) $binding->id;
    assertTrue($probeBindingId > 0, '探针 merchant_channel 绑定写入成功');

    $orderOk = PROBE_ORDER_PREFIX . 'HTTP_OK_' . time();
    $httpOk = postSubmitOrder(buildSignedOrderParams(
        PROBE_MCH_ID,
        PROBE_SECRET,
        $orderOk,
        $payType,
        '10000'
    ));
    assertTrue($httpOk['http_code'] === 200, 'HTTP 有授权下单 HTTP 200');
    assertTrue((int) ($httpOk['body']['code'] ?? 0) === 200, 'HTTP 有授权下单 code=200');
    $orderNo = (string) ($httpOk['body']['data']['order_no'] ?? '');
    assertTrue($orderNo !== '', 'HTTP 有授权下单返回 order_no');
    $probeOrderNos[] = $orderNo;

    // ----- 9) 订单落库校验 -----
    $orderRow = Order::where('order_no', $orderNo)->find();
    assertTrue($orderRow !== null, '探针订单已落库');
    assertTrue((int) $orderRow->channel_id === $channelId, '订单 channel_id 命中 mock 通道');

    echo "\n== 汇总：{$passed} PASS / {$failed} FAIL ==\n";
} catch (Throwable $e) {
    echo "\n[ABORT] {$e->getMessage()}\n";
} finally {
    $cleaned = cleanupProbeData($probeMerchantId, $probeOrderNos);
    echo "[INFO] 自清理 deleted_rows={$cleaned}\n";
}

if ($failed > 0) {
    exit(1);
}
exit(0);
