<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：ScanPayAdapter（真实样例）行为测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service\channel;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\service\SignService;
use plugin\paymentchannel\service\channel\adapters\ScanPayAdapter;
use plugin\paymentchannel\service\channel\dto\ChannelCredential;
use plugin\paymentchannel\service\channel\dto\CreateOrderRequest;
use plugin\paymentchannel\service\channel\dto\PaymentOutcome;

/**
 * ScanPayAdapter 行为测试
 *
 * 用注入的「假 HTTP 传输」断言：下单参数已正确换算（元→分）、已签名、响应解析正确，
 * **不触发真实网络**。覆盖成功/失败分支、回调解析、验签、查单三态。
 */
class ScanPayAdapterTest extends TestCase
{
    /** 上游 MD5 密钥 */
    private const KEY = 'scan_upstream_key';

    /**
     * 构造凭证
     */
    private function credential(): ChannelCredential
    {
        return new ChannelCredential(
            channelId: 2,
            gatewayUrl: 'https://up.example.com/pay',
            upstreamMchId: 'UPMCH001',
            upstreamKey: self::KEY,
            signType: SignService::SIGN_TYPE_MD5,
        );
    }

    /**
     * 下单成功：金额换算为分、参数已签名且可验签、响应正确解析
     */
    public function testCreateOrderSuccess(): void
    {
        $captured = [];
        // 假传输：捕获上游请求参数，返回成功 JSON
        $transport = function (string $url, array $params) use (&$captured): string {
            $captured = ['url' => $url, 'params' => $params];
            return json_encode([
                'code' => 0,
                'msg' => 'ok',
                'data' => ['pay_url' => 'https://up.example.com/qr/abc', 'trade_no' => 'UPTRADE888'],
            ]);
        };
        // 日志用空闭包，避免 DB
        $adapter = new ScanPayAdapter($this->credential(), $transport, fn() => null);

        $result = $adapter->createOrder(new CreateOrderRequest(
            orderNo: 'P20260608130000001',
            amount: '100.0000',
            payType: 6,
            notifyUrl: 'https://plat.example.com/pay/notify/scan',
        ));

        $this->assertTrue($result->success);
        $this->assertSame('https://up.example.com/qr/abc', $result->payUrl);
        $this->assertSame('UPTRADE888', $result->upstreamNo);

        // 提交给上游的金额应为分（100 元 → 10000）
        $this->assertSame('https://up.example.com/pay', $captured['url']);
        $this->assertSame('10000', $captured['params']['money']);
        $this->assertSame('P20260608130000001', $captured['params']['out_trade_no']);
        // 参数已签名且可被上游用同密钥验签
        $this->assertArrayHasKey('sign', $captured['params']);
        $this->assertTrue(SignService::verify($captured['params'], self::KEY, SignService::SIGN_TYPE_MD5));
    }

    /**
     * 下单失败：上游返回非成功码 → 结果为 fail，携带原因
     */
    public function testCreateOrderUpstreamFail(): void
    {
        $transport = fn(string $url, array $params): string => json_encode(['code' => 500, 'msg' => '余额不足']);
        $adapter = new ScanPayAdapter($this->credential(), $transport, fn() => null);

        $result = $adapter->createOrder(new CreateOrderRequest(
            orderNo: 'P2',
            amount: '10.0000',
            payType: 6,
            notifyUrl: 'https://plat.example.com/pay/notify/scan',
        ));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('余额不足', $result->message);
    }

    /**
     * 下单异常：上游成功码但无支付链接 → fail
     */
    public function testCreateOrderMissingPayUrl(): void
    {
        $transport = fn(string $url, array $params): string => json_encode(['code' => 0, 'data' => ['trade_no' => 'X']]);
        $adapter = new ScanPayAdapter($this->credential(), $transport, fn() => null);

        $result = $adapter->createOrder(new CreateOrderRequest(
            orderNo: 'P3',
            amount: '10.0000',
            payType: 6,
            notifyUrl: 'https://plat.example.com/pay/notify/scan',
        ));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('支付链接', $result->message);
    }

    /**
     * 回调解析：trade_status=SUCCESS → 已支付，金额分→元
     */
    public function testParseNotifyPaid(): void
    {
        $adapter = new ScanPayAdapter($this->credential(), null, fn() => null);

        $result = $adapter->parseNotify([
            'out_trade_no' => 'P20260608130000005',
            'trade_no' => 'UP55',
            'money' => '25000',
            'trade_status' => 'SUCCESS',
        ]);

        $this->assertSame(PaymentOutcome::Paid, $result->outcome);
        $this->assertSame('P20260608130000005', $result->orderNo);
        $this->assertSame('250.0000', $result->amount);
        $this->assertSame('UP55', $result->upstreamNo);
    }

    /**
     * 验签：正确签名通过，篡改后失败
     */
    public function testVerifyNotify(): void
    {
        $adapter = new ScanPayAdapter($this->credential(), null, fn() => null);

        $payload = [
            'out_trade_no' => 'P20260608130000006',
            'trade_no' => 'UP66',
            'money' => '30000',
            'trade_status' => 'SUCCESS',
        ];
        $payload['sign'] = SignService::makeSign($payload, self::KEY, SignService::SIGN_TYPE_MD5);
        $this->assertTrue($adapter->verifyNotify($payload));

        $payload['money'] = '1';
        $this->assertFalse($adapter->verifyNotify($payload));
    }

    /**
     * 查单：上游返回 SUCCESS → 已支付；未知状态 → 待支付（避免误关单）
     */
    public function testQueryOrderStates(): void
    {
        // 已支付
        $paidTransport = fn(string $url, array $params): string => json_encode([
            'code' => 0,
            'data' => ['trade_status' => 'SUCCESS', 'money' => '12300', 'trade_no' => 'UPQ1'],
        ]);
        $adapter = new ScanPayAdapter($this->credential(), $paidTransport, fn() => null);
        $paid = $adapter->queryOrder('P20260608130000007');
        $this->assertSame(PaymentOutcome::Paid, $paid->outcome);
        $this->assertSame('123.0000', $paid->amount);
        $this->assertSame('UPQ1', $paid->upstreamNo);

        // 未知状态 → 待支付
        $pendingTransport = fn(string $url, array $params): string => json_encode([
            'code' => 0,
            'data' => ['trade_status' => 'WAIT', 'money' => '0'],
        ]);
        $adapter2 = new ScanPayAdapter($this->credential(), $pendingTransport, fn() => null);
        $pending = $adapter2->queryOrder('P20260608130000008');
        $this->assertSame(PaymentOutcome::Pending, $pending->outcome);
    }
}
