<?php
// +----------------------------------------------------------------------
// | SaiPayment 四方支付渠道系统
// +----------------------------------------------------------------------
// | 插件：paymentchannel（命名空间 plugin\paymentchannel）
// | 文件：MockAdapter 行为测试
// +----------------------------------------------------------------------

namespace plugin\paymentchannel\tests\service\channel;

use PHPUnit\Framework\TestCase;
use plugin\paymentchannel\service\SignService;
use plugin\paymentchannel\service\channel\adapters\MockAdapter;
use plugin\paymentchannel\service\channel\dto\ChannelCredential;
use plugin\paymentchannel\service\channel\dto\CreateOrderRequest;
use plugin\paymentchannel\service\channel\dto\PaymentOutcome;

/**
 * MockAdapter 行为测试
 *
 * 跑通「下单 → 回调解析 → 验签 → 查单」全链路，并验证：
 *  - 注入日志闭包后下单会落一条日志（不触发 DB）；
 *  - 金额分→元换算正确；
 *  - MD5 验签真实生效（正确通过、篡改拒绝）。
 */
class MockAdapterTest extends TestCase
{
    /** MD5 密钥（用于构造回调签名） */
    private const KEY = 'mock_secret_key';

    /**
     * 构造一个注入了内存日志的 MockAdapter
     *
     * @param array<int,array> $logSink 引用：收集日志条目
     * @return MockAdapter
     */
    private function makeAdapter(array &$logSink): MockAdapter
    {
        $credential = new ChannelCredential(
            channelId: 1,
            upstreamKey: self::KEY,
            signType: SignService::SIGN_TYPE_MD5,
        );
        $logger = function (int $type, string $bizNo, string $request, string $response) use (&$logSink): void {
            $logSink[] = compact('type', 'bizNo', 'request', 'response');
        };
        return new MockAdapter($credential, null, $logger);
    }

    /**
     * 下单：返回成功、含订单号的支付链接与模拟上游单号，并落一条日志
     */
    public function testCreateOrderSuccessAndLogs(): void
    {
        $logSink = [];
        $adapter = $this->makeAdapter($logSink);

        $request = new CreateOrderRequest(
            orderNo: 'P20260608120000001',
            amount: '100.0000',
            payType: 3,
            notifyUrl: 'https://plat.example.com/pay/notify/mock',
        );
        $result = $adapter->createOrder($request);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('P20260608120000001', $result->payUrl);
        // 金额以分透传到收银台链接
        $this->assertStringContainsString('money=10000', $result->payUrl);
        $this->assertSame('MOCKP20260608120000001', $result->upstreamNo);
        // 注入日志闭包应被调用一次
        $this->assertCount(1, $logSink);
    }

    /**
     * 回调解析：分→元换算正确、状态映射正确、订单号取自 out_trade_no
     */
    public function testParseNotifyPaid(): void
    {
        $logSink = [];
        $adapter = $this->makeAdapter($logSink);

        $result = $adapter->parseNotify([
            'out_trade_no' => 'P20260608120000002',
            'trade_no' => 'UP998877',
            'money' => '10000',
            'status' => 'success',
        ]);

        $this->assertSame(PaymentOutcome::Paid, $result->outcome);
        $this->assertTrue($result->isPaid());
        $this->assertSame('P20260608120000002', $result->orderNo);
        $this->assertSame('UP998877', $result->upstreamNo);
        $this->assertSame('100.0000', $result->amount);
    }

    /**
     * 回调解析：非 success 状态视为失败
     */
    public function testParseNotifyFailed(): void
    {
        $logSink = [];
        $adapter = $this->makeAdapter($logSink);

        $result = $adapter->parseNotify([
            'out_trade_no' => 'P3',
            'money' => '500',
            'status' => 'closed',
        ]);

        $this->assertSame(PaymentOutcome::Failed, $result->outcome);
        $this->assertSame('5.0000', $result->amount);
    }

    /**
     * 验签：正确签名通过，篡改金额后拒绝
     */
    public function testVerifyNotify(): void
    {
        $logSink = [];
        $adapter = $this->makeAdapter($logSink);

        $payload = [
            'out_trade_no' => 'P20260608120000003',
            'trade_no' => 'UP55',
            'money' => '20000',
            'status' => 'success',
        ];
        // 按相同规则生成正确签名
        $payload['sign'] = SignService::makeSign($payload, self::KEY, SignService::SIGN_TYPE_MD5);
        $this->assertTrue($adapter->verifyNotify($payload));

        // 篡改金额后验签必须失败
        $tampered = $payload;
        $tampered['money'] = '99999';
        $this->assertFalse($adapter->verifyNotify($tampered));
    }

    /**
     * 端到端：下单 → 构造带签回调 → 验签 → 解析为已支付
     */
    public function testEndToEndCreateThenNotify(): void
    {
        $logSink = [];
        $adapter = $this->makeAdapter($logSink);

        $orderNo = 'P20260608120000009';
        $create = $adapter->createOrder(new CreateOrderRequest(
            orderNo: $orderNo,
            amount: '88.8800',
            payType: 1,
            notifyUrl: 'https://plat.example.com/pay/notify/mock',
        ));
        $this->assertTrue($create->success);

        // 模拟上游回调（金额分），带正确签名
        $callback = [
            'out_trade_no' => $orderNo,
            'trade_no' => $create->upstreamNo,
            'money' => '8888',
            'status' => 'success',
        ];
        $callback['sign'] = SignService::makeSign($callback, self::KEY, SignService::SIGN_TYPE_MD5);

        $this->assertTrue($adapter->verifyNotify($callback));
        $parsed = $adapter->parseNotify($callback);
        $this->assertTrue($parsed->isPaid());
        $this->assertSame($orderNo, $parsed->orderNo);
        $this->assertSame('88.8800', $parsed->amount);
        // 回应串
        $this->assertSame('success', $adapter->successResponse());
    }

    /**
     * 查单：Mock 恒返回已支付
     */
    public function testQueryOrderPaid(): void
    {
        $logSink = [];
        $adapter = $this->makeAdapter($logSink);

        $result = $adapter->queryOrder('P20260608120000010');
        $this->assertTrue($result->isPaid());
        $this->assertSame('MOCKP20260608120000010', $result->upstreamNo);
    }
}
