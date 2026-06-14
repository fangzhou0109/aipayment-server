<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：LqpayAdapter 行为测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service\channel;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\service\SignService;
use plugin\paymentchannel\service\channel\adapters\LqpayAdapter;
use plugin\paymentchannel\service\channel\dto\ChannelCredential;
use plugin\paymentchannel\service\channel\dto\CreateOrderRequest;
use plugin\paymentchannel\service\channel\dto\PaymentOutcome;

/**
 * LqpayAdapter 行为测试（SaiPayment 同源上游协议）
 */
class LqpayAdapterTest extends TestCase
{
    private const KEY = 'lqpay_upstream_secret_32chars_xx';

    private function credential(): ChannelCredential
    {
        return new ChannelCredential(
            channelId: 14,
            gatewayUrl: 'https://api.fangzhou.uk/prod/pay',
            upstreamMchId: 'TEST_M001',
            upstreamKey: self::KEY,
            signType: SignService::SIGN_TYPE_MD5,
        );
    }

    public function testCreateOrderSuccess(): void
    {
        $captured = [];
        $transport = function (string $url, array $params) use (&$captured): string {
            $captured = ['url' => $url, 'params' => $params];
            return json_encode([
                'code' => 200,
                'message' => '下单成功',
                'data' => [
                    'order_no' => 'LQ20260610001',
                    'pay_url' => 'https://api.fangzhou.uk/pay/cashier/abc',
                    'upstream_no' => 'UP888',
                    'amount' => '100.0000',
                ],
            ]);
        };
        $adapter = new LqpayAdapter($this->credential(), $transport, fn () => null);

        $result = $adapter->createOrder(new CreateOrderRequest(
            orderNo: 'P20260610000001',
            amount: '100.0000',
            payType: 3,
            notifyUrl: 'https://api.fangzhou.uk/prod/pay/notify/lqpay',
        ));

        $this->assertTrue($result->success);
        $this->assertSame('https://api.fangzhou.uk/pay/cashier/abc', $result->payUrl);
        $this->assertSame('UP888', $result->upstreamNo);
        $this->assertSame('https://api.fangzhou.uk/prod/pay/submitOrder', $captured['url']);
        $this->assertSame('10000', $captured['params']['money']);
        $this->assertSame('P20260610000001', $captured['params']['order_id']);
        $this->assertTrue(SignService::verify($captured['params'], self::KEY, SignService::SIGN_TYPE_MD5));
    }

    public function testCreateOrderUpstreamFail(): void
    {
        $transport = fn (string $url, array $params): string => json_encode(['code' => 400, 'message' => '商户未配置可用支付通道']);
        $adapter = new LqpayAdapter($this->credential(), $transport, fn () => null);

        $result = $adapter->createOrder(new CreateOrderRequest(
            orderNo: 'P2',
            amount: '10.0000',
            payType: 3,
            notifyUrl: 'https://plat.example.com/pay/notify/lqpay',
        ));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('商户未配置', $result->message);
    }

    public function testParseNotifyPaid(): void
    {
        $adapter = new LqpayAdapter($this->credential(), null, fn () => null);

        $result = $adapter->parseNotify([
            'order_id' => 'P20260610000005',
            'order_no' => 'LQ55',
            'money' => '25000',
            'mch_id' => 'TEST_M001',
            'status' => 'success',
            'time' => '1718000000',
        ]);

        $this->assertSame(PaymentOutcome::Paid, $result->outcome);
        $this->assertSame('P20260610000005', $result->orderNo);
        $this->assertSame('250.0000', $result->amount);
        $this->assertSame('LQ55', $result->upstreamNo);
    }

    public function testVerifyNotify(): void
    {
        $adapter = new LqpayAdapter($this->credential(), null, fn () => null);

        $payload = [
            'order_id' => 'P20260610000006',
            'order_no' => 'LQ66',
            'money' => '30000',
            'mch_id' => 'TEST_M001',
            'status' => 'success',
            'time' => '1718000001',
        ];
        $payload['sign'] = SignService::makeSign($payload, self::KEY, SignService::SIGN_TYPE_MD5);
        $this->assertTrue($adapter->verifyNotify($payload));

        $payload['money'] = '1';
        $this->assertFalse($adapter->verifyNotify($payload));
    }

    public function testQueryOrderStates(): void
    {
        $paidTransport = fn (string $url, array $params): string => json_encode([
            'code' => 200,
            'data' => [
                'trade_status' => 'SUCCESS',
                'amount' => '123.0000',
                'upstream_no' => 'UPQ1',
            ],
        ]);
        $adapter = new LqpayAdapter($this->credential(), $paidTransport, fn () => null);
        $paid = $adapter->queryOrder('P20260610000007');
        $this->assertSame(PaymentOutcome::Paid, $paid->outcome);
        $this->assertSame('123.0000', $paid->amount);

        $pendingTransport = fn (string $url, array $params): string => json_encode([
            'code' => 200,
            'data' => ['trade_status' => 'NOTPAY', 'amount' => '10.0000'],
        ]);
        $adapter2 = new LqpayAdapter($this->credential(), $pendingTransport, fn () => null);
        $pending = $adapter2->queryOrder('P20260610000008');
        $this->assertSame(PaymentOutcome::Pending, $pending->outcome);
    }

    public function testSuccessResponseIsUppercaseSuccess(): void
    {
        $adapter = new LqpayAdapter($this->credential(), null, fn () => null);
        $this->assertSame('SUCCESS', $adapter->successResponse());
    }
}
